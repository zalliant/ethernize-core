<?php

declare(strict_types=1);

namespace Ethernize\Core\Routing;

use LogicException;

/**
 * Erro de programação na definição de uma rota. Ocorre no registro ou na
 * compilação das rotas, nunca por causa de dados de uma requisição.
 */
final class InvalidRouteException extends LogicException
{
}
