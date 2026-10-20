<?php

declare(strict_types=1);

namespace Ethernize\Core\Routing;

/**
 * Valida o caminho de uma rota e o transforma em expressão regular.
 *
 * Parâmetros ocupam um segmento inteiro: {id}, {id:int}. Aliases disponíveis:
 * int, slug, alpha e uuid. Expressões próprias entram por RouteBuilder::where().
 * Um "?" ao final do nome ({pagina?}, {pagina:int?}) torna o parâmetro opcional;
 * parâmetros opcionais só podem ocupar os últimos segmentos.
 */
final class RoutePattern
{
    private const ALIASES = [
        'int' => '[0-9]+',
        'slug' => '[a-z0-9]+(?:-[a-z0-9]+)*',
        'alpha' => '[A-Za-z]+',
        'uuid' => '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}',
    ];

    private const DEFAULT = '[^/]+';

    /**
     * @param array<string, string> $constraints
     */
    public static function compile(string $path, array $constraints): CompiledPattern
    {
        if ($path === '' || $path[0] !== '/') {
            throw new InvalidRouteException(sprintf('O caminho "%s" deve começar com "/".', $path));
        }

        $segments = [];
        $names = [];
        $regex = '';
        $open = 0;

        if ($path !== '/') {
            foreach (explode('/', substr($path, 1)) as $segment) {
                if ($segment === '') {
                    throw new InvalidRouteException(sprintf('O caminho "%s" contém segmento vazio.', $path));
                }

                if (!str_contains($segment, '{') && !str_contains($segment, '}')) {
                    if ($open > 0) {
                        throw new InvalidRouteException(sprintf('Em "%s", segmentos fixos não podem vir depois de parâmetros opcionais.', $path));
                    }

                    if (preg_match('/^[A-Za-z0-9\-._~%]+$/', $segment) !== 1 || $segment === '.' || $segment === '..') {
                        throw new InvalidRouteException(sprintf('O segmento "%s" contém caracteres não permitidos.', $segment));
                    }

                    $segments[] = ['literal' => $segment];
                    $regex .= '/' . preg_quote($segment, '~');
                    continue;
                }

                if (preg_match('/^\{([A-Za-z_][A-Za-z0-9_]*)(?::([a-z]+))?(\?)?\}$/', $segment, $parts) !== 1) {
                    throw new InvalidRouteException(sprintf('Parâmetro inválido "%s" em "%s".', $segment, $path));
                }

                $name = $parts[1];
                $optional = ($parts[3] ?? '') === '?';

                if (in_array($name, $names, true)) {
                    throw new InvalidRouteException(sprintf('O parâmetro "%s" aparece mais de uma vez em "%s".', $name, $path));
                }

                if (!$optional && $open > 0) {
                    throw new InvalidRouteException(sprintf('Em "%s", parâmetros obrigatórios não podem vir depois de opcionais.', $path));
                }

                $alias = ($parts[2] ?? '') !== '' ? $parts[2] : null;
                $pattern = self::patternFor($name, $alias, $constraints);
                $names[] = $name;
                $segments[] = ['param' => $name, 'pattern' => $pattern, 'optional' => $optional];

                if ($optional) {
                    $regex .= '(?:/(?P<' . $name . '>' . $pattern . ')';
                    $open++;
                } else {
                    $regex .= '/(?P<' . $name . '>' . $pattern . ')';
                }
            }
        }

        foreach (array_keys($constraints) as $constrained) {
            if (!in_array($constrained, $names, true)) {
                throw new InvalidRouteException(sprintf('where() refere-se ao parâmetro inexistente "%s" em "%s".', $constrained, $path));
            }
        }

        if ($names === []) {
            return new CompiledPattern(null, [], $segments);
        }

        return new CompiledPattern('~^' . $regex . str_repeat(')?', $open) . '$~D', $names, $segments);
    }

    /**
     * @param array<string, string> $constraints
     */
    private static function patternFor(string $name, ?string $alias, array $constraints): string
    {
        if (isset($constraints[$name])) {
            $pattern = str_replace('~', '\~', $constraints[$name]);

            if (@preg_match('~^(?:' . $pattern . ')$~', '') === false) {
                throw new InvalidRouteException(sprintf('Expressão inválida para o parâmetro "%s".', $name));
            }

            // Parâmetros pertencem a um único segmento. Isso garante que a busca por regex e a por árvore de segmentos concordem.
            foreach (['/', '//', 'a/b', 'a/', '/a', '0/0', 'a/b/c'] as $probe) {
                if (preg_match('~^(?:' . $pattern . ')$~D', $probe) === 1) {
                    throw new InvalidRouteException(sprintf('A restrição do parâmetro "%s" não pode casar o caractere "/".', $name));
                }
            }

            return '(?:' . $pattern . ')';
        }

        if ($alias === null) {
            return self::DEFAULT;
        }

        return self::ALIASES[$alias]
            ?? throw new InvalidRouteException(sprintf('Alias de parâmetro desconhecido "%s".', $alias));
    }
}
