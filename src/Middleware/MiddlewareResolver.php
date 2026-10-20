<?php

declare(strict_types=1);

namespace Ethernize\Core\Middleware;

use Ethernize\Core\Container\Container;
use InvalidArgumentException;
use Psr\Http\Server\MiddlewareInterface;

/**
 * Converte as referências de middleware declaradas pelo desenvolvedor em
 * instâncias. Apenas classes que implementam MiddlewareInterface são aceitas.
 */
final class MiddlewareResolver
{
    public function __construct(private readonly Container $container)
    {
    }

    public function resolve(MiddlewareInterface|string $middleware): MiddlewareInterface
    {
        if ($middleware instanceof MiddlewareInterface) {
            return $middleware;
        }

        if (!is_subclass_of($middleware, MiddlewareInterface::class)) {
            throw new InvalidArgumentException(sprintf('"%s" não é um middleware PSR-15.', $middleware));
        }

        $this->container->allow($middleware);

        /** @var MiddlewareInterface */
        return $this->container->get($middleware);
    }

    /**
     * @param list<MiddlewareInterface|string> $middleware
     * @return list<MiddlewareInterface>
     */
    public function resolveAll(array $middleware): array
    {
        return array_map($this->resolve(...), $middleware);
    }
}
