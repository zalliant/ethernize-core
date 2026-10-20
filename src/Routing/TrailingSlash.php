<?php

declare(strict_types=1);

namespace Ethernize\Core\Routing;

/**
 * Como tratar "/caminho/" quando a rota é "/caminho".
 */
enum TrailingSlash: string
{
    /** Trata os dois caminhos como a mesma rota (padrão). */
    case Ignore = 'ignore';

    /** Responde 308 para o caminho sem a barra final. */
    case Redirect = 'redirect';

    /** Responde 404 para caminhos com barra final. */
    case Strict = 'strict';
}
