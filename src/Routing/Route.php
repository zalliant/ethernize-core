<?php

declare(strict_types=1);

namespace Ethernize\Core\Routing;

use Closure;
use Psr\Http\Server\MiddlewareInterface;

final class Route
{
    /**
     * @param Closure|string|array{0: class-string, 1: string}   $handler
     * @param list<MiddlewareInterface|string>                   $middleware
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly Closure|string|array $handler,
        public readonly array $middleware,
        public readonly CompiledPattern $pattern,
        public readonly ?string $name = null,
    ) {
    }
}
