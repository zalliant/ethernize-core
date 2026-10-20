<?php

declare(strict_types=1);

namespace Ethernize\Core\Events;

use Ethernize\Core\Routing\Route;
use Psr\Http\Message\ServerRequestInterface;

/** Disparado quando uma rota é encontrada, antes do middleware de rota. */
final class RouteMatched
{
    public function __construct(
        public readonly ServerRequestInterface $request,
        public readonly Route $route,
    ) {
    }
}
