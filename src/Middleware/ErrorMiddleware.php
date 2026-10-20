<?php

declare(strict_types=1);

namespace Ethernize\Core\Middleware;

use Ethernize\Core\Http\ExceptionHandler;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

final class ErrorMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly ExceptionHandler $handler)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            return $handler->handle($request);
        } catch (Throwable $exception) {
            return $this->handler->render($exception, $request);
        }
    }
}
