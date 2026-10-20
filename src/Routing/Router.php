<?php

declare(strict_types=1);

namespace Ethernize\Core\Routing;

use Closure;
use Ethernize\Core\Http\HttpException;
use InvalidArgumentException;
use Psr\Http\Server\MiddlewareInterface;

/**
 * Armazena as rotas e resolve método e caminho em uma rota.
 *
 * Rotas estáticas são buscadas por tabela hash. Rotas com parâmetros são
 * testadas em sequência, na ordem de registro, dentro do método HTTP.
 * Rotas ambíguas (mesmo método e mesma forma de caminho) são recusadas na
 * compilação. Quando duas rotas diferem apenas pela restrição de um parâmetro,
 * vale a que foi registrada primeiro.
 */
final class Router implements RouteResolver
{
    /** @var list<RouteBuilder> */
    private array $builders = [];

    /** @var list<array{0: string, 1: list<MiddlewareInterface|string>}> */
    private array $groups = [];

    /** @var array<string, array<string, Route>>|null */
    private ?array $static = null;

    /** @var array<string, list<Route>> */
    private array $dynamic = [];

    /** @var array<string, Route> */
    private array $names = [];

    public function on(string $path): RouteBuilder
    {
        $prefix = '';
        $middleware = [];

        foreach ($this->groups as [$groupPrefix, $groupMiddleware]) {
            $prefix .= '/' . trim($groupPrefix, '/');
            array_push($middleware, ...$groupMiddleware);
        }

        $builder = new RouteBuilder(self::normalize($prefix . '/' . ltrim($path, '/')), $middleware);
        $this->builders[] = $builder;
        $this->static = null;

        return $builder;
    }

    /**
     * @param list<MiddlewareInterface|string> $middleware
     */
    public function group(string $prefix, Closure $routes, array $middleware = []): void
    {
        $this->groups[] = [$prefix, $middleware];

        try {
            $routes();
        } finally {
            array_pop($this->groups);
        }
    }

    /**
     * @throws HttpException 404 quando nenhum caminho corresponde, 405 quando o caminho existe para outros métodos
     */
    public function match(string $method, string $path): RouteMatch
    {
        $this->compiled();

        $path = self::normalize($path);
        $method = strtoupper($method);
        $candidates = $method === 'HEAD' ? ['HEAD', 'GET'] : [$method];

        foreach ($candidates as $candidate) {
            if (isset($this->static[$path][$candidate])) {
                return new RouteMatch($this->static[$path][$candidate], []);
            }

            foreach ($this->dynamic[$candidate] ?? [] as $route) {
                $parameters = $this->extract($route, $path);

                if ($parameters !== null) {
                    return new RouteMatch($route, $parameters);
                }
            }
        }

        $allowed = $this->allowedMethods($path);

        throw $allowed === [] ? HttpException::notFound() : HttpException::methodNotAllowed($allowed);
    }

    /**
     * Rota registrada com ->name(). Qualquer método do mesmo caminho serve
     * para gerar a URL.
     */
    public function named(string $name): Route
    {
        $this->compiled();

        return $this->names[$name] ?? throw new InvalidArgumentException(sprintf('Não existe rota com o nome "%s".', $name));
    }

    /**
     * @return list<Route>
     */
    public function routes(): array
    {
        $this->compiled();

        $routes = [];

        foreach ($this->static ?? [] as $byMethod) {
            array_push($routes, ...array_values($byMethod));
        }

        foreach ($this->dynamic as $byMethod) {
            array_push($routes, ...$byMethod);
        }

        return $routes;
    }

    private function compiled(): void
    {
        if ($this->static !== null) {
            return;
        }

        $static = [];
        $dynamic = [];
        $names = [];
        $seen = [];

        foreach ($this->builders as $builder) {
            $pattern = RoutePattern::compile($builder->path, $builder->constraints());
            $name = $builder->routeName();

            if ($builder->entries() === [] && $name !== null) {
                throw new InvalidRouteException(sprintf('A rota nomeada "%s" não define nenhum método.', $name));
            }

            foreach ($builder->entries() as [$method, $handler]) {
                $route = new Route($method, $builder->path, $handler, $builder->middlewareList(), $pattern, $name);

                foreach ($pattern->signatures() as $signature) {
                    if (isset($seen[$method][$signature])) {
                        throw new InvalidRouteException(sprintf(
                            '%s %s conflita com %s, que já cobre o mesmo caminho.',
                            $method,
                            $builder->path,
                            $seen[$method][$signature],
                        ));
                    }

                    $seen[$method][$signature] = $builder->path;
                }

                if ($pattern->regex === null) {
                    $static[$builder->path][$method] = $route;
                } else {
                    $dynamic[$method][] = $route;
                }

                if ($name !== null) {
                    $names[$name] ??= $route;
                }
            }
        }

        $this->checkNames();
        $this->dynamic = $dynamic;
        $this->names = $names;
        $this->static = $static;
    }

    private function checkNames(): void
    {
        $owners = [];

        foreach ($this->builders as $builder) {
            $name = $builder->routeName();

            if ($name === null) {
                continue;
            }

            if (isset($owners[$name])) {
                throw new InvalidRouteException(sprintf('O nome de rota "%s" já é usado por %s.', $name, $owners[$name]));
            }

            $owners[$name] = $builder->path;
        }
    }

    /**
     * @return array<string, string>|null
     */
    private function extract(Route $route, string $path): ?array
    {
        // A raiz "/" é o caminho vazio para as expressões, o que permite "/{x?}" casar com "/".
        if ($route->pattern->regex === null || preg_match($route->pattern->regex, $path === '/' ? '' : $path, $matches) !== 1) {
            return null;
        }

        $parameters = [];

        foreach ($route->pattern->parameters as $name) {
            if (!isset($matches[$name]) || $matches[$name] === '') {
                continue;
            }

            $value = rawurldecode($matches[$name]);

            if (preg_match('//u', $value) !== 1 || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
                return null;
            }

            $parameters[$name] = $value;
        }

        return $parameters;
    }

    /**
     * Métodos aceitos no caminho, incluindo HEAD (quando há GET) e OPTIONS.
     * Vazio quando o caminho não existe.
     *
     * @return list<string>
     */
    private function allowedMethods(string $path): array
    {
        $allowed = array_keys($this->static[$path] ?? []);

        foreach ($this->dynamic as $method => $routes) {
            foreach ($routes as $route) {
                if ($this->extract($route, $path) !== null) {
                    $allowed[] = $method;
                    break;
                }
            }
        }

        if ($allowed === []) {
            return [];
        }

        if (in_array('GET', $allowed, true)) {
            $allowed[] = 'HEAD';
        }

        $allowed[] = 'OPTIONS';
        $allowed = array_values(array_unique($allowed));
        sort($allowed);

        return $allowed;
    }

    private static function normalize(string $path): string
    {
        $path = rtrim($path, '/');

        return $path === '' ? '/' : $path;
    }
}
