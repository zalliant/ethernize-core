<?php

declare(strict_types=1);

namespace Ethernize\Core\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Executa middlewares PSR-15 na ordem em que foram informados, terminando no
 * handler final. É imutável, então pode ser reutilizado e é seguro em
 * chamadas reentrantes.
 */
final class Pipeline implements RequestHandlerInterface
{
    /**
     * @param list<MiddlewareInterface> $middleware
     */
    public function __construct(
        private readonly array $middleware,
        private readonly RequestHandlerInterface $fallback,
        private readonly int $index = 0,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $current = $this->middleware[$this->index] ?? null;

        if ($current === null) {
            return $this->fallback->handle($request);
        }

        return $current->process($request, new self($this->middleware, $this->fallback, $this->index + 1));
    }
}
