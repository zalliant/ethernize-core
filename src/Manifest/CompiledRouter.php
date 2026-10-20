<?php

declare(strict_types=1);

namespace Ethernize\Core\Manifest;

use Ethernize\Core\Http\HttpException;
use Ethernize\Core\Routing\CompiledPattern;
use Ethernize\Core\Routing\Route;
use Ethernize\Core\Routing\RouteMatch;
use Ethernize\Core\Routing\RouteResolver;
use InvalidArgumentException;

/**
 * Resolve rotas a partir das tabelas geradas pelo Manifest.
 *
 * Rotas estáticas ficam em uma tabela hash. Rotas com parâmetros ficam em uma
 * árvore de segmentos, de modo que o custo depende do número de segmentos do
 * caminho e não do número de rotas. Para ter o mesmo resultado do Router
 * convencional, quando mais de uma rota dinâmica casa o mesmo método vale a
 * que foi registrada primeiro (menor índice).
 *
 * Formato de nó da árvore:
 *   'l' => [segmento literal => nó]
 *   'p' => [[nome, expressão|null, nó], ...]   (null: qualquer segmento não vazio)
 *   'm' => [método => índice da rota]
 *
 * @phpstan-type Node array{l?: array<string, mixed>, p?: list<array{0: string, 1: ?string, 2: array<string, mixed>}>, m?: array<string, int>}
 */
final class CompiledRouter implements RouteResolver
{
    /** @var array<int, Route> */
    private array $built = [];

    /**
     * @param list<array{0: string, 1: string, 2: mixed, 3: list<string>, 4: list<string>, 5: list<array<string, mixed>>, 6: ?string}> $routes
     * @param array<string, array<string, int>>                                                                                           $static
     * @param array<string, mixed>                                                                                                        $tree
     * @param array<string, int>                                                                                                           $names
     */
    public function __construct(
        private readonly array $routes,
        private readonly array $static,
        private readonly array $tree,
        private readonly array $names,
    ) {
    }

    public function match(string $method, string $path): RouteMatch
    {
        $path = rtrim($path, '/');
        $path = $path === '' ? '/' : $path;
        $method = strtoupper($method);
        $candidates = $method === 'HEAD' ? ['HEAD', 'GET'] : [$method];
        $found = null;

        foreach ($candidates as $candidate) {
            if (isset($this->static[$path][$candidate])) {
                return new RouteMatch($this->route($this->static[$path][$candidate]), []);
            }

            $found ??= $this->walkTree($path);

            if (isset($found[$candidate])) {
                [$index, $parameters] = $found[$candidate];

                return new RouteMatch($this->route($index), $parameters);
            }
        }

        $allowed = array_keys($this->static[$path] ?? []);
        array_push($allowed, ...array_keys($found ?? $this->walkTree($path)));

        if ($allowed === []) {
            throw HttpException::notFound();
        }

        if (in_array('GET', $allowed, true)) {
            $allowed[] = 'HEAD';
        }

        $allowed[] = 'OPTIONS';
        $allowed = array_values(array_unique($allowed));
        sort($allowed);

        throw HttpException::methodNotAllowed($allowed);
    }

    public function named(string $name): Route
    {
        if (!isset($this->names[$name])) {
            throw new InvalidArgumentException(sprintf('Não existe rota com o nome "%s".', $name));
        }

        return $this->route($this->names[$name]);
    }

    public function routes(): array
    {
        return array_map($this->route(...), array_keys($this->routes));
    }

    /**
     * @return array<string, array{0: int, 1: array<string, string>}> método => [índice, parâmetros]
     */
    private function walkTree(string $path): array
    {
        if ($this->tree === []) {
            return [];
        }

        $found = [];
        $segments = $path === '/' ? [] : explode('/', substr($path, 1));
        $this->walk($this->tree, $segments, 0, count($segments), [], $found);

        return $found;
    }

    /**
     * @param array<string, mixed>                                    $node
     * @param list<string>                                            $segments
     * @param array<string, string>                                   $parameters
     * @param array<string, array{0: int, 1: array<string, string>}>  $found
     */
    private function walk(array $node, array $segments, int $position, int $count, array $parameters, array &$found): void
    {
        if ($position === $count) {
            foreach ($node['m'] ?? [] as $method => $index) {
                if (!isset($found[$method]) || $index < $found[$method][0]) {
                    $found[$method] = [$index, $parameters];
                }
            }

            return;
        }

        $segment = $segments[$position];

        if (isset($node['l'][$segment])) {
            $this->walk($node['l'][$segment], $segments, $position + 1, $count, $parameters, $found);
        }

        foreach ($node['p'] ?? [] as [$name, $regex, $child]) {
            if ($segment === '' || ($regex !== null && preg_match($regex, $segment) !== 1)) {
                continue;
            }

            $value = rawurldecode($segment);

            if (preg_match('//u', $value) !== 1 || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
                continue;
            }

            $this->walk($child, $segments, $position + 1, $count, [...$parameters, $name => $value], $found);
        }
    }

    private function route(int $index): Route
    {
        if (isset($this->built[$index])) {
            return $this->built[$index];
        }

        [$method, $path, $handler, $middleware, $parameters, $segments, $name] = $this->routes[$index];

        return $this->built[$index] = new Route(
            $method,
            $path,
            $handler,
            $middleware,
            new CompiledPattern(null, $parameters, $segments),
            $name,
        );
    }
}
