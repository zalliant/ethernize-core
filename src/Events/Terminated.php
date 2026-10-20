<?php

declare(strict_types=1);

namespace Ethernize\Core\Events;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Disparado depois que a resposta foi enviada ao cliente. É o ponto para
 * liberar recursos (conexões, arquivos, filas) de módulos como o Gate.
 */
final class Terminated
{
    public function __construct(
        public readonly ServerRequestInterface $request,
        public readonly ResponseInterface $response,
    ) {
    }
}
