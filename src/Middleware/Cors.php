<?php

declare(strict_types=1);

namespace Ethernize\Core\Middleware;

use Ethernize\Core\Http\Response;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * CORS restritivo. Nada é permitido até que origens sejam listadas.
 *
 * - Origens são comparadas literalmente (esquema, host e porta). O curinga "*"
 *   só é aceito se declarado explicitamente e nunca junto com credenciais.
 * - Métodos e cabeçalhos pedidos em um preflight precisam estar nas listas
 *   configuradas; caso contrário a resposta é 403.
 * - A origem "null" nunca é aceita.
 * - A resposta sempre inclui Vary: Origin, para não contaminar caches.
 */
final class Cors implements MiddlewareInterface
{
    /** @var list<string> */
    private readonly array $origins;

    /** @var list<string> */
    private readonly array $methods;

    /** @var list<string> */
    private readonly array $headers;

    /** @var list<string> */
    private readonly array $exposed;

    /**
     * @param list<string> $origins
     * @param list<string> $methods
     * @param list<string> $headers  cabeçalhos de requisição aceitos no preflight
     * @param list<string> $exposed  cabeçalhos de resposta visíveis ao navegador
     */
    public function __construct(
        array $origins,
        array $methods = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'],
        array $headers = [],
        array $exposed = [],
        private readonly int $maxAge = 600,
        private readonly bool $credentials = false,
    ) {
        foreach ($origins as $origin) {
            if ($origin !== '*' && preg_match('~^https?://[A-Za-z0-9](?:[A-Za-z0-9.-]*[A-Za-z0-9])?(?::[0-9]{1,5})?$~', $origin) !== 1) {
                throw new InvalidArgumentException(sprintf('Origem CORS inválida: "%s". Use esquema://host[:porta], sem barra final.', $origin));
            }
        }

        if (in_array('*', $origins, true) && $credentials) {
            throw new InvalidArgumentException('CORS com credenciais não pode usar a origem "*".');
        }

        if ($maxAge < 0 || $maxAge > 86400) {
            throw new InvalidArgumentException('max_age de CORS deve ficar entre 0 e 86400.');
        }

        foreach ($methods as $method) {
            if (preg_match('/^[A-Z]+$/', $method) !== 1) {
                throw new InvalidArgumentException(sprintf('Método CORS inválido: "%s".', $method));
            }
        }

        foreach ([...$headers, ...$exposed] as $header) {
            if (preg_match('/^[A-Za-z0-9-]+$/', $header) !== 1) {
                throw new InvalidArgumentException(sprintf('Cabeçalho CORS inválido: "%s".', $header));
            }
        }

        $this->origins = array_values($origins);
        $this->methods = array_values($methods);
        $this->headers = array_values($headers);
        $this->exposed = array_values($exposed);
    }

    /**
     * @param array<string, mixed> $config chaves: origins, methods, headers, exposed_headers, max_age, credentials
     */
    public static function fromConfig(array $config): self
    {
        $list = static fn (string $key, array $default = []): array => array_values((array) ($config[$key] ?? $default));

        return new self(
            $list('origins'),
            $list('methods', ['GET', 'POST', 'PUT', 'PATCH', 'DELETE']),
            $list('headers'),
            $list('exposed_headers'),
            (int) ($config['max_age'] ?? 600),
            ($config['credentials'] ?? false) === true,
        );
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $origin = $request->getHeaderLine('Origin');
        $preflight = strtoupper($request->getMethod()) === 'OPTIONS'
            && $origin !== ''
            && $request->hasHeader('Access-Control-Request-Method');

        if ($origin === '' || !$this->isAllowed($origin)) {
            $response = $preflight ? Response::empty(403) : $handler->handle($request);

            return $this->vary($response);
        }

        $allowOrigin = in_array('*', $this->origins, true) ? '*' : $origin;

        if ($preflight) {
            return $this->preflight($request, $allowOrigin);
        }

        $response = $this->vary($handler->handle($request))->withHeader('Access-Control-Allow-Origin', $allowOrigin);

        if ($this->credentials) {
            $response = $response->withHeader('Access-Control-Allow-Credentials', 'true');
        }

        if ($this->exposed !== []) {
            $response = $response->withHeader('Access-Control-Expose-Headers', implode(', ', $this->exposed));
        }

        return $response;
    }

    private function preflight(ServerRequestInterface $request, string $allowOrigin): ResponseInterface
    {
        $method = strtoupper(trim($request->getHeaderLine('Access-Control-Request-Method')));

        if (!in_array($method, $this->methods, true)) {
            return $this->vary(Response::empty(403));
        }

        $requested = [];

        foreach (explode(',', $request->getHeaderLine('Access-Control-Request-Headers')) as $header) {
            $header = strtolower(trim($header));

            if ($header !== '') {
                $requested[] = $header;
            }
        }

        $allowedHeaders = array_map('strtolower', $this->headers);

        if (array_diff($requested, $allowedHeaders) !== []) {
            return $this->vary(Response::empty(403));
        }

        $response = $this->vary(Response::empty(204))
            ->withHeader('Access-Control-Allow-Origin', $allowOrigin)
            ->withHeader('Access-Control-Allow-Methods', implode(', ', $this->methods))
            ->withHeader('Access-Control-Max-Age', (string) $this->maxAge);

        if ($this->headers !== []) {
            $response = $response->withHeader('Access-Control-Allow-Headers', implode(', ', $this->headers));
        }

        return $this->credentials ? $response->withHeader('Access-Control-Allow-Credentials', 'true') : $response;
    }

    private function isAllowed(string $origin): bool
    {
        return in_array('*', $this->origins, true) || in_array($origin, $this->origins, true);
    }

    private function vary(ResponseInterface $response): ResponseInterface
    {
        foreach ($response->getHeader('Vary') as $value) {
            if (strcasecmp(trim($value), 'Origin') === 0) {
                return $response;
            }
        }

        return $response->withAddedHeader('Vary', 'Origin');
    }
}
