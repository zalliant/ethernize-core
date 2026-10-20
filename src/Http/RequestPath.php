<?php

declare(strict_types=1);

namespace Ethernize\Core\Http;

/**
 * Valida o caminho bruto da requisição antes de qualquer roteamento.
 *
 * O caminho é decodificado uma única vez, nunca duas. São recusados com 400:
 * escapes percentuais malformados, barras codificadas (%2F e %5C), segmentos
 * vazios, segmentos "." e ".." (inclusive codificados), caracteres de
 * controle, espaços, barra invertida e bytes que não formam UTF-8 válido.
 */
final class RequestPath
{
    public const MAX_LENGTH = 2048;

    private function __construct()
    {
    }

    /**
     * @throws HttpException
     */
    public static function assertValid(string $path): void
    {
        if ($path === '' || $path === '/') {
            return;
        }

        if (strlen($path) > self::MAX_LENGTH) {
            throw new HttpException(414);
        }

        if (
            $path[0] !== '/'
            || preg_match('/[\x00-\x20\x7F\\\\]/', $path) === 1
            || preg_match('/%(?![0-9A-Fa-f]{2})/', $path) === 1
            || preg_match('/%(?:2[Ff]|5[Cc])/', $path) === 1
        ) {
            throw new HttpException(400);
        }

        $inner = substr($path, 1);

        if (str_ends_with($inner, '/')) {
            $inner = substr($inner, 0, -1);
        }

        foreach (explode('/', $inner) as $segment) {
            $decoded = rawurldecode($segment);

            if (
                $segment === ''
                || $decoded === '.'
                || $decoded === '..'
                || preg_match('/[\x00-\x1F\x7F]/', $decoded) === 1
                || preg_match('//u', $decoded) !== 1
            ) {
                throw new HttpException(400);
            }
        }
    }
}
