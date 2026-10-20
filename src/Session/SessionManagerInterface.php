<?php

declare(strict_types=1);

namespace Ethernize\Core\Session;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Abre a sessão a partir da requisição e a persiste na resposta (por exemplo,
 * gravando o cookie). Quando houver uma implementação registrada no container,
 * o Core a executa em toda requisição e expõe a sessão em RequestAttributes::SESSION.
 */
interface SessionManagerInterface
{
    public function start(ServerRequestInterface $request): SessionInterface;

    public function commit(SessionInterface $session, ResponseInterface $response): ResponseInterface;
}
