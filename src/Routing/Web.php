<?php

declare(strict_types=1);

namespace Ethernize\Core\Routing;

use Closure;
use LogicException;
use Psr\Http\Server\MiddlewareInterface;

/**
 * Fachada experimental para declarar rotas em arquivos de rotas.
 *
 * Ela não guarda rotas por conta própria: apenas aponta para o Router ativo,
 * definido por Web::within() enquanto a aplicação carrega seus arquivos de
 * rotas. Fora disso, qualquer chamada falha, o que mantém aplicações e testes
 * isolados entre si.
 */
final class Web
{
    private static ?Router $active = null;

    private function __construct()
    {
    }

    public static function on(string $path): RouteBuilder
    {
        return self::router()->on($path);
    }

    /**
     * @param list<MiddlewareInterface|string> $middleware
     */
    public static function group(string $prefix, Closure $routes, array $middleware = []): void
    {
        self::router()->group($prefix, $routes, $middleware);
    }

    public static function within(Router $router, Closure $callback): void
    {
        $previous = self::$active;
        self::$active = $router;

        try {
            $callback();
        } finally {
            self::$active = $previous;
        }
    }

    private static function router(): Router
    {
        return self::$active
            ?? throw new LogicException('Web só pode declarar rotas enquanto a aplicação carrega seus arquivos de rotas.');
    }
}
