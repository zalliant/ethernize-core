<?php

declare(strict_types=1);

namespace Ethernize\Core\Http;

use InvalidArgumentException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;

/**
 * Fábrica de respostas PSR-7 para os casos mais comuns.
 */
final class Response
{
    private const JSON_FLAGS = JSON_THROW_ON_ERROR
        | JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_HEX_TAG
        | JSON_HEX_AMP
        | JSON_HEX_APOS
        | JSON_HEX_QUOT;

    private static ?Psr17Factory $factory = null;

    /**
     * @param array<string, string> $headers
     */
    public static function html(string $body, int $status = 200, array $headers = []): ResponseInterface
    {
        return self::make($body, $status, 'text/html; charset=UTF-8', $headers);
    }

    /**
     * @param array<string, string> $headers
     */
    public static function text(string $body, int $status = 200, array $headers = []): ResponseInterface
    {
        return self::make($body, $status, 'text/plain; charset=UTF-8', $headers);
    }

    /**
     * @param array<string, string> $headers
     */
    public static function json(mixed $data, int $status = 200, array $headers = []): ResponseInterface
    {
        return self::make(json_encode($data, self::JSON_FLAGS), $status, 'application/json; charset=UTF-8', $headers);
    }

    public static function redirect(string $location, int $status = 302): ResponseInterface
    {
        if (preg_match('/[\x00-\x1F\x7F]/', $location) === 1) {
            throw new InvalidArgumentException('O destino do redirecionamento contém caracteres de controle.');
        }

        $scheme = parse_url($location, PHP_URL_SCHEME);

        if ($scheme === null || $scheme === false) {
            // Sem esquema, só caminhos locais: "//host" e "/\host" são interpretados pelos navegadores como outro site.
            if (str_starts_with($location, '//') || str_contains($location, '\\')) {
                throw new InvalidArgumentException('O destino do redirecionamento não pode ser relativo ao protocolo nem conter barra invertida.');
            }
        } elseif (!in_array(strtolower($scheme), ['http', 'https'], true) || str_contains($location, '\\')) {
            throw new InvalidArgumentException('O destino do redirecionamento deve ser relativo ou usar http/https.');
        }

        return self::factory()->createResponse($status)->withHeader('Location', $location);
    }

    /**
     * Indica se o texto é um caminho local seguro para redirecionar (por
     * exemplo, um parâmetro "voltar_para" vindo do usuário).
     */
    public static function isLocalPath(string $location): bool
    {
        return preg_match('/^\/(?![\/\\\\])[^\x00-\x1F\x7F\\\\]*$/', $location) === 1;
    }

    public static function empty(int $status = 204): ResponseInterface
    {
        return self::factory()->createResponse($status);
    }

    /**
     * @param array<string, string> $headers
     */
    private static function make(string $body, int $status, string $contentType, array $headers): ResponseInterface
    {
        $factory = self::factory();
        $response = $factory->createResponse($status)
            ->withHeader('Content-Type', $contentType)
            ->withBody($factory->createStream($body));

        foreach ($headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }

    private static function factory(): Psr17Factory
    {
        return self::$factory ??= new Psr17Factory();
    }
}
