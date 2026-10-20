<?php

declare(strict_types=1);

namespace Ethernize\Core\Routing;

use Closure;
use Psr\Http\Server\MiddlewareInterface;
use ReflectionMethod;
use ReflectionException;

/**
 * Descreve um caminho e os métodos HTTP atendidos por ele.
 *
 * middleware() e where() valem para todos os métodos declarados no builder,
 * independentemente da ordem das chamadas.
 */
final class RouteBuilder
{
    /** @var list<array{0: string, 1: Closure|string|array{0: class-string, 1: string}}> */
    private array $entries = [];

    /** @var list<MiddlewareInterface|string> */
    private array $middleware;

    /** @var array<string, string> */
    private array $constraints = [];

    private ?string $name = null;

    /**
     * @param list<MiddlewareInterface|string> $inheritedMiddleware
     */
    public function __construct(
        public readonly string $path,
        array $inheritedMiddleware = [],
    ) {
        $this->middleware = $inheritedMiddleware;
    }

    /** @param Closure|string|array{0: string, 1: string} $handler */
    public function get(Closure|string|array $handler): self
    {
        return $this->add('GET', $handler);
    }

    /**
     * HEAD explícito. Sem ele, HEAD é atendido pela rota GET do mesmo caminho.
     *
     * @param Closure|string|array{0: string, 1: string} $handler
     */
    public function head(Closure|string|array $handler): self
    {
        return $this->add('HEAD', $handler);
    }

    /**
     * OPTIONS explícito. Sem ele, o Core responde 204 com o cabeçalho Allow.
     *
     * @param Closure|string|array{0: string, 1: string} $handler
     */
    public function options(Closure|string|array $handler): self
    {
        return $this->add('OPTIONS', $handler);
    }

    /** @param Closure|string|array{0: string, 1: string} $handler */
    public function post(Closure|string|array $handler): self
    {
        return $this->add('POST', $handler);
    }

    /** @param Closure|string|array{0: string, 1: string} $handler */
    public function put(Closure|string|array $handler): self
    {
        return $this->add('PUT', $handler);
    }

    /** @param Closure|string|array{0: string, 1: string} $handler */
    public function patch(Closure|string|array $handler): self
    {
        return $this->add('PATCH', $handler);
    }

    /** @param Closure|string|array{0: string, 1: string} $handler */
    public function delete(Closure|string|array $handler): self
    {
        return $this->add('DELETE', $handler);
    }

    public function middleware(MiddlewareInterface|string ...$middleware): self
    {
        array_push($this->middleware, ...$middleware);

        return $this;
    }

    /**
     * Nomeia o caminho para a geração de URLs. Os nomes são únicos na aplicação.
     */
    public function name(string $name): self
    {
        if (preg_match('/^[A-Za-z0-9_.-]+$/', $name) !== 1) {
            throw new InvalidRouteException(sprintf('Nome de rota inválido: "%s".', $name));
        }

        $this->name = $name;

        return $this;
    }

    public function where(string $parameter, string $regex): self
    {
        $this->constraints[$parameter] = $regex;

        return $this;
    }

    /**
     * @return list<array{0: string, 1: Closure|string|array{0: class-string, 1: string}}>
     */
    public function entries(): array
    {
        return $this->entries;
    }

    /**
     * @return list<MiddlewareInterface|string>
     */
    public function middlewareList(): array
    {
        return $this->middleware;
    }

    public function routeName(): ?string
    {
        return $this->name;
    }

    /**
     * @return array<string, string>
     */
    public function constraints(): array
    {
        return $this->constraints;
    }

    /** @param Closure|string|array{0: string, 1: string} $handler */
    private function add(string $method, Closure|string|array $handler): self
    {
        foreach ($this->entries as [$existing]) {
            if ($existing === $method) {
                throw new InvalidRouteException(sprintf('%s %s já foi definida.', $method, $this->path));
            }
        }

        $this->entries[] = [$method, self::validate($handler)];

        return $this;
    }

    /**
     * Handlers são apenas Closures, classes invocáveis ou métodos públicos de
     * instância. Nada vindo da requisição participa dessa escolha.
     *
     * @param Closure|string|array{0: string, 1: string} $handler
     * @return Closure|string|array{0: class-string, 1: string}
     */
    private static function validate(Closure|string|array $handler): Closure|string|array
    {
        if ($handler instanceof Closure) {
            return $handler;
        }

        if (is_string($handler)) {
            self::assertMethod($handler, '__invoke');

            return $handler;
        }

        if (count($handler) !== 2 || !isset($handler[0], $handler[1]) || !is_string($handler[0]) || !is_string($handler[1])) {
            throw new InvalidRouteException('Um handler em array deve ter o formato [Classe::class, \'metodo\'].');
        }

        if (str_starts_with($handler[1], '__') && $handler[1] !== '__invoke') {
            throw new InvalidRouteException(sprintf('O método mágico "%s" não pode ser usado como handler.', $handler[1]));
        }

        self::assertMethod($handler[0], $handler[1]);

        return [$handler[0], $handler[1]];
    }

    private static function assertMethod(string $class, string $method): void
    {
        if (!class_exists($class)) {
            throw new InvalidRouteException(sprintf('A classe "%s" do handler não existe.', $class));
        }

        try {
            $reflection = new ReflectionMethod($class, $method);
        } catch (ReflectionException) {
            throw new InvalidRouteException(sprintf('"%s::%s" não existe.', $class, $method));
        }

        if (!$reflection->isPublic() || $reflection->isStatic() || $reflection->isAbstract()) {
            throw new InvalidRouteException(sprintf('"%s::%s" deve ser um método público de instância.', $class, $method));
        }
    }
}
