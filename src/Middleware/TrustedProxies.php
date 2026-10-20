<?php

declare(strict_types=1);

namespace Ethernize\Core\Middleware;

use Ethernize\Core\Http\RequestAttributes;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Define o IP do cliente e o esquema da requisição.
 *
 * Cabeçalhos X-Forwarded-* são ignorados por completo, a menos que a conexão
 * venha de um proxy listado em http.trusted_proxies (IPs ou blocos CIDR).
 * Mesmo então, apenas X-Forwarded-For (para o IP do cliente) e
 * X-Forwarded-Proto (http ou https) são considerados. X-Forwarded-Host nunca é usado.
 * O IP do cliente é o primeiro endereço, da direita para a esquerda, que não
 * pertence a um proxy confiável.
 */
final class TrustedProxies implements MiddlewareInterface
{
    /** @var list<array{0: string, 1: int}> endereço binário e comprimento do prefixo em bits */
    private readonly array $proxies;

    /**
     * @param list<string> $proxies
     */
    public function __construct(array $proxies = [])
    {
        $parsed = [];

        foreach ($proxies as $entry) {
            $parsed[] = self::parse($entry);
        }

        $this->proxies = $parsed;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $remote = $request->getServerParams()['REMOTE_ADDR'] ?? null;
        $remote = is_string($remote) && filter_var($remote, FILTER_VALIDATE_IP) !== false ? $remote : null;
        $client = $remote;

        if ($remote !== null && $this->isTrusted($remote)) {
            $client = $this->forwardedClient($request) ?? $remote;
            $proto = strtolower(trim(explode(',', $request->getHeaderLine('X-Forwarded-Proto'))[0]));

            if ($proto === 'http' || $proto === 'https') {
                $request = $request->withUri($request->getUri()->withScheme($proto)->withPort(null));
            }
        }

        return $handler->handle($request->withAttribute(RequestAttributes::CLIENT_IP, $client));
    }

    private function forwardedClient(ServerRequestInterface $request): ?string
    {
        $header = $request->getHeaderLine('X-Forwarded-For');

        if ($header === '') {
            return null;
        }

        $addresses = array_map('trim', explode(',', $header));
        $leftmost = null;

        foreach (array_reverse($addresses) as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP) === false) {
                return null;
            }

            $leftmost = $address;

            if (!$this->isTrusted($address)) {
                return $address;
            }
        }

        return $leftmost;
    }

    private function isTrusted(string $ip): bool
    {
        $binary = inet_pton($ip);

        if ($binary === false) {
            return false;
        }

        foreach ($this->proxies as [$network, $bits]) {
            if (strlen($network) !== strlen($binary)) {
                continue;
            }

            $full = intdiv($bits, 8);
            $rest = $bits % 8;

            if (substr($binary, 0, $full) !== substr($network, 0, $full)) {
                continue;
            }

            if ($rest === 0) {
                return true;
            }

            $mask = (0xFF << (8 - $rest)) & 0xFF;

            if ((ord($binary[$full]) & $mask) === (ord($network[$full]) & $mask)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{0: string, 1: int}
     */
    private static function parse(string $entry): array
    {
        [$address, $prefix] = array_pad(explode('/', $entry, 2), 2, null);
        $binary = filter_var($address, FILTER_VALIDATE_IP) !== false ? inet_pton($address) : false;

        if ($binary === false) {
            throw new InvalidArgumentException(sprintf('Proxy confiável inválido: "%s".', $entry));
        }

        $max = strlen($binary) * 8;

        if ($prefix === null) {
            return [$binary, $max];
        }

        if (preg_match('/^[0-9]{1,3}$/', $prefix) !== 1 || (int) $prefix > $max) {
            throw new InvalidArgumentException(sprintf('Prefixo CIDR inválido: "%s".', $entry));
        }

        return [$binary, (int) $prefix];
    }
}
