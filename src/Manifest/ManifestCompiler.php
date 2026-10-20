<?php

declare(strict_types=1);

namespace Ethernize\Core\Manifest;

use Closure;
use Ethernize\Core\Application;
use Ethernize\Core\Config\Config;
use Ethernize\Core\Config\Environment;
use Ethernize\Core\Routing\Route;
use Psr\Http\Server\MiddlewareInterface;
use ReflectionClass;

/**
 * Gera o Manifest de uma aplicação.
 *
 * A aplicação é inicializada no modo convencional (providers, arquivos de
 * rotas e configuração são executados normalmente) e o resultado é congelado.
 * Isso só deve rodar em ambiente controlado, no processo de publicação, nunca
 * durante o atendimento de uma requisição.
 *
 * Recusa o que não pode ser congelado com segurança: rotas com Closure,
 * middlewares que sejam objetos e configuração que contenha objetos.
 */
final class ManifestCompiler
{
    /**
     * @return array{source: string, stats: array<string, mixed>}
     */
    public static function compile(string $basePath): array
    {
        $started = hrtime(true);
        $root = realpath($basePath);

        if ($root === false || !is_dir($root . '/config')) {
            throw new ManifestException(sprintf('"%s" não parece ser uma aplicação Ethernize (pasta config/ ausente).', $basePath));
        }

        $environment = Environment::load($root);
        $config = Config::fromDirectory($root . '/config', $environment);
        $app = new Application($root, $config);

        try {
            $app->boot();
        } catch (\Throwable $exception) {
            throw new ManifestException('A aplicação não inicializa, então não pode ser compilada: ' . $exception->getMessage(), 0, $exception);
        }

        $problems = [];
        self::assertExportable($config->all(), 'config', $problems);

        $routes = $app->router()->routes();
        $table = self::routeTable($routes, $problems);
        $classes = self::serviceClasses($routes, $config);

        $extensions = [];
        $extraSources = [];

        foreach ($app->manifestContributors() as $contributor) {
            $key = $contributor->key();

            try {
                $contribution = $contributor->contribute($app);
            } catch (\Throwable $exception) {
                $problems[] = sprintf('módulo "%s": %s', $key, $exception->getMessage());
                continue;
            }

            self::assertExportable($contribution->data, 'extensions.' . $key, $problems);
            $extensions[$key] = $contribution->data;
            array_push($extraSources, ...$contribution->sources);
        }

        if ($problems !== []) {
            throw ManifestException::withProblems('Não foi possível compilar o Manifest:', $problems);
        }

        $services = new ServiceCompiler($app->container());
        $expressions = $services->compile($classes);

        $meta = [
            'format' => Manifest::FORMAT,
            'core' => Application::VERSION,
            'php' => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
            'env' => $app->environment(),
            'sources' => self::sources($root, $config, array_keys($expressions), $extraSources),
        ];

        $source = ManifestFile::render($meta, $config->all(), $table, $expressions, $extensions);

        return [
            'source' => $source,
            'stats' => [
                'routes' => count($routes),
                'static' => count(array_filter($routes, static fn (Route $r): bool => $r->pattern->regex === null)),
                'dynamic' => count(array_filter($routes, static fn (Route $r): bool => $r->pattern->regex !== null)),
                'services' => count($expressions),
                'skipped' => $services->skipped(),
                'sources' => count($meta['sources']),
                'bytes' => strlen($source),
                'milliseconds' => (hrtime(true) - $started) / 1e6,
            ],
        ];
    }

    /**
     * Compila e grava em {base}/var/cache/ethernize/manifest.php.
     *
     * @return array<string, mixed> estatísticas
     */
    public static function build(string $basePath): array
    {
        $result = self::compile($basePath);
        ManifestFile::write(ManifestFile::path($basePath), $result['source']);

        return $result['stats'];
    }

    /**
     * @param list<Route>  $routes
     * @param list<string> $problems
     * @return array{routes: list<array<int, mixed>>, static: array<string, array<string, int>>, tree: array<string, mixed>, names: array<string, int>}
     */
    private static function routeTable(array $routes, array &$problems): array
    {
        $entries = [];
        $static = [];
        $tree = [];
        $names = [];

        foreach ($routes as $index => $route) {
            $label = $route->method . ' ' . $route->path;

            if ($route->handler instanceof Closure) {
                $problems[] = $label . ': handlers com Closure não podem ser compilados. Use [Classe::class, \'metodo\'] ou uma classe invocável.';
            }

            foreach ($route->middleware as $middleware) {
                if (!is_string($middleware) || !is_subclass_of($middleware, MiddlewareInterface::class)) {
                    $problems[] = $label . ': o middleware deve ser o nome de uma classe que implementa MiddlewareInterface (objetos não podem ser compilados).';
                }
            }

            $entries[] = [
                $route->method,
                $route->path,
                $route->handler instanceof Closure ? null : $route->handler,
                array_values(array_filter($route->middleware, 'is_string')),
                $route->pattern->parameters,
                $route->pattern->segments,
                $route->name,
            ];

            if ($route->pattern->regex === null) {
                $static[$route->path][$route->method] = $index;
            } else {
                $segments = $route->pattern->segments;
                $minimum = count($segments);

                while ($minimum > 0 && ($segments[$minimum - 1]['optional'] ?? false)) {
                    $minimum--;
                }

                $tree = self::insert($tree, $segments, 0, $minimum, $route->method, $index);
            }

            if ($route->name !== null) {
                $names[$route->name] ??= $index;
            }
        }

        return ['routes' => $entries, 'static' => $static, 'tree' => $tree, 'names' => $names];
    }

    /**
     * Insere a rota na árvore de segmentos. Parâmetros opcionais finais
     * registram a rota também nos nós intermediários.
     *
     * As rotas devem ser inseridas em ordem crescente de índice: em caso de
     * empate no mesmo nó e método, vale a primeira.
     *
     * @param array<string, mixed>                $node
     * @param list<array<string, mixed>>          $segments
     * @return array<string, mixed>
     */
    private static function insert(array $node, array $segments, int $depth, int $minimum, string $method, int $index): array
    {
        if ($depth >= $minimum) {
            $node['m'][$method] ??= $index;
        }

        if ($depth === count($segments)) {
            return $node;
        }

        $segment = $segments[$depth];

        if (isset($segment['literal'])) {
            $node['l'][$segment['literal']] = self::insert($node['l'][$segment['literal']] ?? [], $segments, $depth + 1, $minimum, $method, $index);

            return $node;
        }

        $regex = $segment['pattern'] === '[^/]+' ? null : '~^(?:' . $segment['pattern'] . ')$~D';
        $position = null;

        foreach ($node['p'] ?? [] as $candidate => $edge) {
            if ($edge[0] === $segment['param'] && $edge[1] === $regex) {
                $position = $candidate;
                break;
            }
        }

        if ($position === null) {
            $node['p'][] = [$segment['param'], $regex, []];
            $position = array_key_last($node['p']);
        }

        $node['p'][$position][2] = self::insert($node['p'][$position][2], $segments, $depth + 1, $minimum, $method, $index);

        return $node;
    }

    /**
     * @param list<Route> $routes
     * @return list<class-string>
     */
    private static function serviceClasses(array $routes, Config $config): array
    {
        $classes = [];

        foreach ($routes as $route) {
            if (!$route->handler instanceof Closure) {
                $handler = $route->handler;
                $classes[] = is_array($handler) ? $handler[0] : $handler;
            }

            foreach ($route->middleware as $middleware) {
                if (is_string($middleware)) {
                    $classes[] = $middleware;
                }
            }
        }

        foreach ((array) $config->get('app.middleware', []) as $middleware) {
            if (is_string($middleware)) {
                $classes[] = $middleware;
            }
        }

        return array_values(array_unique(array_filter($classes, static fn (mixed $c): bool => is_string($c) && class_exists($c))));
    }

    /**
     * Arquivos cujo conteúdo, se mudar, torna o Manifest obsoleto.
     *
     * @param list<string> $serviceClasses
     * @param list<string> $extraFiles arquivos informados por contribuidores
     * @return array<string, string> caminho relativo => hash
     */
    private static function sources(string $root, Config $config, array $serviceClasses, array $extraFiles = []): array
    {
        $files = $extraFiles;

        foreach (['.env'] as $name) {
            $files[] = $root . '/' . $name;
        }

        $configDirectory = $root . '/config';

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($configDirectory, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        foreach ((array) $config->get('app.routes', []) as $route) {
            if (is_string($route)) {
                $files[] = $root . '/' . $route;
            }
        }

        foreach ($serviceClasses as $class) {
            $file = (new ReflectionClass($class))->getFileName();

            if ($file !== false) {
                $files[] = $file;
            }
        }

        $sources = [];

        foreach ($files as $file) {
            $real = realpath($file);

            if ($real === false || !is_file($real) || !str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
                continue;
            }

            $sources[str_replace(DIRECTORY_SEPARATOR, '/', substr($real, strlen($root) + 1))] = (string) hash_file('xxh3', $real);
        }

        ksort($sources);

        return $sources;
    }

    /**
     * @param list<string> $problems
     */
    private static function assertExportable(mixed $value, string $path, array &$problems): void
    {
        if ($value === null || is_scalar($value)) {
            return;
        }

        if (is_array($value)) {
            foreach ($value as $key => $item) {
                self::assertExportable($item, $path . '.' . $key, $problems);
            }

            return;
        }

        $problems[] = sprintf('%s contém %s, que não pode ser gravado no Manifest. Use apenas textos, números, listas e arrays.', $path, get_debug_type($value));
    }
}
