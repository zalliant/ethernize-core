<?php

declare(strict_types=1);

namespace Ethernize\Core\Http;

/**
 * Atributos da requisição preenchidos pelo Core. Módulos como Shield e Pulse
 * podem lê-los em seus middlewares.
 */
final class RequestAttributes
{
    /** Route encontrada pelo roteador. */
    public const ROUTE = 'ethernize.route';

    /** Parâmetros de rota já decodificados. */
    public const ROUTE_PARAMS = 'ethernize.route_params';

    /** Endereço IP do cliente, considerando proxies somente se confiáveis. */
    public const CLIENT_IP = 'ethernize.client_ip';

    /** Prefixo removido do caminho (instalação em subdiretório). */
    public const BASE_PATH = 'ethernize.base_path';

    /** SessionInterface da requisição, quando houver gerenciador de sessões. */
    public const SESSION = 'ethernize.session';

    private function __construct()
    {
    }
}
