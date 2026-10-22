<?php

declare(strict_types=1);

namespace Ethernize\Core\Routing;

use Closure;
use Ethernize\Core\Container\Container;
use Ethernize\Core\Container\ParameterTypeException;
use Ethernize\Core\Http\CurrentRequest;
use Ethernize\Core\Http\HttpException;
use Ethernize\Core\Http\RequestAttributes;
use Ethernize\Core\Http\Response;
use Ethernize\Core\View\TemplateRendererInterface;
use Ethernize\Core\View\View;
use JsonSerializable;
use LogicException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Executa o handler de uma rota já encontrada e normaliza o retorno.
 *
 * Retornos aceitos: ResponseInterface, string (HTML), array ou JsonSerializable (JSON).
 */
final class RouteHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly Route $route,
        private readonly Container $container,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        // Whatever renders during this call (components, for instance) must see this request, not an earlier copy.
        if ($this->container->isBound(CurrentRequest::class)) {
            $this->container->get(CurrentRequest::class)->set($request);
        }

        /** @var array<string, string> $parameters */
        $parameters = $request->getAttribute(RequestAttributes::ROUTE_PARAMS, []);
        $byType = [ServerRequestInterface::class => $request];

        try {
            $result = $this->container->call($this->callable(), $parameters, $byType);
        } catch (ParameterTypeException) {
            throw HttpException::notFound();
        }

        return $this->toResponse($result);
    }

    /**
     * @return Closure|array{0: object, 1: string}
     */
    private function callable(): Closure|array
    {
        $handler = $this->route->handler;

        if ($handler instanceof Closure) {
            return $handler;
        }

        [$class, $method] = is_array($handler) ? $handler : [$handler, '__invoke'];

        $this->container->allow($class);

        return [$this->container->get($class), $method];
    }

    private function render(View $view): ResponseInterface
    {
        if (!$this->container->has(TemplateRendererInterface::class)) {
            throw new LogicException('Nenhum TemplateRendererInterface registrado para renderizar "' . $view->template . '".');
        }

        /** @var TemplateRendererInterface $renderer */
        $renderer = $this->container->get(TemplateRendererInterface::class);

        return Response::html($renderer->render($view->template, $view->data), $view->status);
    }

    private function toResponse(mixed $result): ResponseInterface
    {
        return match (true) {
            $result instanceof ResponseInterface => $result,
            $result instanceof View => $this->render($result),
            is_string($result) => Response::html($result),
            is_array($result), $result instanceof JsonSerializable => Response::json($result),
            default => throw new LogicException(sprintf(
                'O handler de %s %s retornou %s. Retorne uma resposta, string, array ou JsonSerializable.',
                $this->route->method,
                $this->route->path,
                get_debug_type($result),
            )),
        };
    }
}
