<?php

declare(strict_types=1);

namespace Ethernize\Core\Events;

use Psr\Http\Message\ServerRequestInterface;

/** Disparado quando a aplicação começa a tratar uma requisição, antes dos middlewares. */
final class RequestReceived
{
    public function __construct(public readonly ServerRequestInterface $request)
    {
    }
}
