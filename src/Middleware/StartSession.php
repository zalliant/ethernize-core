<?php

declare(strict_types=1);

namespace Ethernize\Core\Middleware;

use Ethernize\Core\Http\RequestAttributes;
use Ethernize\Core\Session\LazySession;
use Ethernize\Core\Session\SessionManagerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Expõe a sessão da requisição e a persiste na resposta. A sessão só é aberta
 * (SessionManagerInterface::start) quando alguém a usa, e só é gravada
 * (commit) se tiver sido aberta. Só entra no pipeline quando há um
 * SessionManagerInterface registrado.
 */
final class StartSession implements MiddlewareInterface
{
    public function __construct(private readonly SessionManagerInterface $manager)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $session = new LazySession($this->manager, $request);
        $response = $handler->handle($request->withAttribute(RequestAttributes::SESSION, $session));
        $started = $session->started();

        return $started === null ? $response : $this->manager->commit($started, $response);
    }
}
