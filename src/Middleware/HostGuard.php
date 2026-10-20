<?php

declare(strict_types=1);

namespace Ethernize\Core\Middleware;

use Ethernize\Core\Http\HttpException;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Valida o cabeçalho Host.
 *
 * Sempre recusa (400) Host malformado ou repetido. Quando uma lista de hosts
 * confiáveis é configurada (http.trusted_hosts), recusa também qualquer host
 * fora dela. Entradas aceitam o curinga de subdomínio: "*.exemplo.com" casa
 * "a.exemplo.com", mas não "exemplo.com". A porta é ignorada na comparação.
 *
 * O Core não usa o Host para montar URLs (veja UrlGenerator), mas módulos e
 * aplicações podem usá-lo, e então a lista de confiáveis é a defesa contra
 * ataques de Host header em produção.
 */
final class HostGuard implements MiddlewareInterface
{
    private const SYNTAX = '/^(?:[A-Za-z0-9](?:[A-Za-z0-9.-]*[A-Za-z0-9])?|\[[0-9A-Fa-f:.]+\])(?::[0-9]{1,5})?$/';

    /** @var list<string> */
    private readonly array $trusted;

    /**
     * @param list<string> $trustedHosts
     */
    public function __construct(array $trustedHosts = [])
    {
        $trusted = [];

        foreach ($trustedHosts as $host) {
            $host = strtolower($host);

            if (preg_match('/^(?:\*\.)?[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?$|^\[[0-9a-f:.]+\]$/', $host) !== 1) {
                throw new InvalidArgumentException(sprintf('Host confiável inválido: "%s".', $host));
            }

            $trusted[] = $host;
        }

        $this->trusted = $trusted;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Algumas fábricas PSR-7 repetem o mesmo Host; só valores diferentes indicam ambiguidade.
        $values = array_values(array_unique(array_map('strtolower', $request->getHeader('Host'))));

        if ($values === []) {
            if ($this->trusted !== []) {
                throw new HttpException(400);
            }

            return $handler->handle($request);
        }

        if (count($values) !== 1 || preg_match(self::SYNTAX, $values[0]) !== 1) {
            throw new HttpException(400);
        }

        if ($this->trusted !== [] && !$this->isTrusted($values[0])) {
            throw new HttpException(400);
        }

        return $handler->handle($request);
    }

    private function isTrusted(string $hostHeader): bool
    {
        $host = strtolower(preg_replace('/(?<=[A-Za-z0-9\]]):[0-9]{1,5}$/', '', $hostHeader) ?? '');

        foreach ($this->trusted as $allowed) {
            if ($allowed === $host) {
                return true;
            }

            if (str_starts_with($allowed, '*.') && str_ends_with($host, substr($allowed, 1)) && strlen($host) > strlen($allowed) - 1) {
                return true;
            }
        }

        return false;
    }
}
