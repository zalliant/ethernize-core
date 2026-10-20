<?php

declare(strict_types=1);

namespace Ethernize\Core\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Cabeçalhos defensivos básicos. Valores já definidos pela aplicação são
 * preservados. CSP e HSTS ficam de fora por dependerem de cada projeto.
 */
final class SecurityHeaders implements MiddlewareInterface
{
    private const DEFAULTS = [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => 'SAMEORIGIN',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
    ];

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        foreach (self::DEFAULTS as $name => $value) {
            if (!$response->hasHeader($name)) {
                $response = $response->withHeader($name, $value);
            }
        }

        return $response;
    }
}
