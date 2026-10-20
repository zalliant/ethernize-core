<?php

declare(strict_types=1);

namespace Ethernize\Core\Routing;

use InvalidArgumentException;
use LogicException;

/**
 * Gera URLs a partir de rotas nomeadas.
 *
 * O host nunca vem da requisição: URLs absolutas usam apenas a base
 * configurada em app.url. Valores de parâmetros são validados contra as
 * restrições da rota e codificados; parâmetros desconhecidos são recusados.
 */
final class UrlGenerator
{
    private string $basePath = '';
    private ?string $origin = null;

    public function __construct(private readonly RouteResolver $router, ?string $baseUrl = null)
    {
        if ($baseUrl === null || $baseUrl === '') {
            return;
        }

        $parts = parse_url($baseUrl);

        if (
            $parts === false
            || !in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            || !isset($parts['host'])
            || isset($parts['user'])
            || isset($parts['query'])
            || isset($parts['fragment'])
        ) {
            throw new InvalidArgumentException('app.url deve ser uma URL http(s) absoluta, sem credenciais, query ou fragmento.');
        }

        $this->origin = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        $this->basePath = rtrim($parts['path'] ?? '', '/');
    }

    /**
     * Caminho (com a base da aplicação, se houver) para a rota nomeada.
     *
     * @param array<string, scalar|null>   $parameters
     * @param array<string, mixed>         $query
     */
    public function path(string $name, array $parameters = [], array $query = []): string
    {
        $route = $this->router->named($name);
        $segments = $route->pattern->segments;

        $known = [];

        foreach ($segments as $segment) {
            if (isset($segment['param'])) {
                $known[$segment['param']] = true;
            }
        }

        $unknown = array_diff_key($parameters, $known);

        if ($unknown !== []) {
            throw new InvalidArgumentException(sprintf('Parâmetros desconhecidos para a rota "%s": %s.', $name, implode(', ', array_keys($unknown))));
        }

        $parts = [];
        $skipped = false;

        foreach ($segments as $segment) {
            if (isset($segment['literal'])) {
                $parts[] = $segment['literal'];
                continue;
            }

            $param = $segment['param'];
            $value = $parameters[$param] ?? null;

            if ($value === null) {
                if (!$segment['optional']) {
                    throw new InvalidArgumentException(sprintf('Falta o parâmetro "%s" da rota "%s".', $param, $name));
                }

                $skipped = true;
                continue;
            }

            if ($skipped) {
                throw new InvalidArgumentException(sprintf('O parâmetro "%s" foi informado, mas um opcional anterior da rota "%s" não.', $param, $name));
            }

            if (!is_scalar($value) || is_bool($value)) {
                throw new InvalidArgumentException(sprintf('O parâmetro "%s" deve ser texto ou número.', $param));
            }

            $encoded = rawurlencode((string) $value);

            if ($encoded === '' || preg_match('~^(?:' . $segment['pattern'] . ')$~D', $encoded) !== 1) {
                throw new InvalidArgumentException(sprintf('O valor do parâmetro "%s" não é aceito pela rota "%s".', $param, $name));
            }

            $parts[] = $encoded;
        }

        $url = $this->basePath . '/' . implode('/', $parts);

        if ($query !== []) {
            $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        return $url;
    }

    /**
     * URL absoluta. Exige app.url configurado.
     *
     * @param array<string, scalar|null> $parameters
     * @param array<string, mixed>       $query
     */
    public function absolute(string $name, array $parameters = [], array $query = []): string
    {
        if ($this->origin === null) {
            throw new LogicException('Configure app.url para gerar URLs absolutas.');
        }

        return $this->origin . $this->path($name, $parameters, $query);
    }
}
