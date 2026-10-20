<?php

declare(strict_types=1);

namespace Ethernize\Core\Http;

use Psr\Http\Message\ResponseInterface;

/**
 * Envia uma resposta PSR-7 ao cliente via SAPI.
 */
final class Emitter
{
    private const CHUNK_SIZE = 8192;

    public static function emit(ResponseInterface $response, string $requestMethod = 'GET'): void
    {
        if (!headers_sent()) {
            header_remove('X-Powered-By');

            foreach ($response->getHeaders() as $name => $values) {
                $replace = strtolower($name) !== 'set-cookie';

                foreach ($values as $value) {
                    header($name . ': ' . $value, $replace);
                    $replace = false;
                }
            }

            header(sprintf(
                'HTTP/%s %d %s',
                $response->getProtocolVersion(),
                $response->getStatusCode(),
                $response->getReasonPhrase(),
            ), true, $response->getStatusCode());
        }

        $status = $response->getStatusCode();

        if (strtoupper($requestMethod) === 'HEAD' || $status < 200 || $status === 204 || $status === 304) {
            return;
        }

        $body = $response->getBody();

        if ($body->isSeekable()) {
            $body->rewind();
        }

        while (!$body->eof()) {
            echo $body->read(self::CHUNK_SIZE);
        }
    }
}
