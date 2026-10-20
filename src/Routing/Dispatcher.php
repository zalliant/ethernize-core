<?php

declare(strict_types=1);

namespace Ethernize\Core\Routing;

use Ethernize\Core\Container\Container;
use Ethernize\Core\Events\RouteMatched;
use Ethernize\Core\Http\HttpException;
use Ethernize\Core\Http\RequestAttributes;
use Ethernize\Core\Http\RequestPath;
use Ethernize\Core\Http\Response;
use Ethernize\Core\Middleware\MiddlewareResolver;
use Ethernize\Core\Middleware\Pipeline;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Handler final da aplicação: valida o caminho, aplica a política de barra
 * final, encontra a rota e executa seu middleware e handler.
 */
final class Dispatcher implements RequestHandlerInterface
{
    public function __construct(
        private readonly RouteResolver $router,
        private readonly Container $container,
        private readonly MiddlewareResolver $middleware,
        private readonly EventDispatcherInterface $events,
        private readonly TrailingSlash $trailingSlash = TrailingSlash::Ignore,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        RequestPath::assertValid($path);

        if ($path !== '' && $path !== '/' && str_ends_with($path, '/')) {
            $redirect = $this->applyTrailingSlash($request, $path);

            if ($redirect !== null) {
                return $redirect;
            }
        }

        try {
            $match = $this->router->match($request->getMethod(), $path === '' ? '/' : $path);
        } catch (HttpException $exception) {
            if ($exception->status === 405 && strtoupper($request->getMethod()) === 'OPTIONS') {
                return Response::empty(204)->withHeader('Allow', $exception->headers['Allow']);
            }

            throw $exception;
        }

        $request = $request
            ->withAttribute(RequestAttributes::ROUTE, $match->route)
            ->withAttribute(RequestAttributes::ROUTE_PARAMS, $match->parameters);

        $this->events->dispatch(new RouteMatched($request, $match->route));

        return (new Pipeline(
            $this->middleware->resolveAll($match->route->middleware),
            new RouteHandler($match->route, $this->container),
        ))->handle($request);
    }

    private function applyTrailingSlash(ServerRequestInterface $request, string $path): ?ResponseInterface
    {
        if ($this->trailingSlash === TrailingSlash::Ignore) {
            return null;
        }

        if ($this->trailingSlash === TrailingSlash::Strict) {
            throw HttpException::notFound();
        }

        // O caminho já foi validado: não há "//" nem barra invertida, logo o destino é sempre local.
        $base = $request->getAttribute(RequestAttributes::BASE_PATH, '');
        $location = (is_string($base) ? $base : '') . rtrim($path, '/');
        $query = $request->getUri()->getQuery();

        if ($query !== '') {
            $location .= '?' . $query;
        }

        return Response::redirect($location, 308);
    }
}
