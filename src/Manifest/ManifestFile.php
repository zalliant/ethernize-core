<?php

declare(strict_types=1);

namespace Ethernize\Core\Manifest;

use Ethernize\Core\Application;
use Ethernize\Core\Filesystem\AtomicPhpFile;
use Throwable;

/**
 * Leitura, escrita e verificação do arquivo de Manifest.
 *
 * O arquivo é PHP comum (carregável e armazenável pelo OPcache) que apenas
 * retorna um array. Ele nunca é lido a partir de um caminho vindo da
 * requisição: o local é sempre {base}/var/cache/ethernize/manifest.php.
 */
final class ManifestFile
{
    public const RELATIVE_PATH = 'var/cache/ethernize/manifest.php';

    private const HASH_PLACEHOLDER = '0000000000000000';
    private const HASH_PATTERN = "/'hash' => '([0-9a-f]{16})'/";

    private function __construct()
    {
    }

    public static function path(string $basePath): string
    {
        return rtrim($basePath, '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, self::RELATIVE_PATH);
    }

    /**
     * Gera o código-fonte do arquivo. $services mapeia classe => expressão PHP
     * (que usa a variável $c, o container).
     *
     * @param array<string, mixed>  $meta   sem a chave "hash"
     * @param array<string, mixed>  $config
     * @param array<string, mixed>  $routes
     * @param array<string, string> $services
     * @param array<string, mixed>  $extensions contribuições de módulos, por chave
     */
    public static function render(array $meta, array $config, array $routes, array $services, array $extensions = []): string
    {
        $meta['hash'] = self::HASH_PLACEHOLDER;

        $code = "<?php\n\ndeclare(strict_types=1);\n\n"
            . "// Gerado pelo Ethernize Manifest. Não edite: gere novamente com bin/manifest.php build.\n\n"
            . "return [\n"
            . '    \'meta\' => ' . var_export($meta, true) . ",\n"
            . '    \'config\' => ' . var_export($config, true) . ",\n"
            . '    \'routes\' => ' . var_export($routes['routes'], true) . ",\n"
            . '    \'static\' => ' . var_export($routes['static'], true) . ",\n"
            . '    \'tree\' => ' . var_export($routes['tree'], true) . ",\n"
            . '    \'names\' => ' . var_export($routes['names'], true) . ",\n"
            . '    \'extensions\' => ' . var_export($extensions, true) . ",\n"
            . "    'services' => [\n";

        foreach ($services as $class => $expression) {
            $code .= '        ' . var_export($class, true)
                . ' => static function (\Ethernize\Core\Container\Container $c): object { return ' . $expression . "; },\n";
        }

        $code .= "    ],\n];\n";

        return preg_replace(self::HASH_PATTERN, "'hash' => '" . self::hash($code) . "'", $code, 1) ?? $code;
    }

    /**
     * Grava de forma atômica: o conteúdo é escrito e validado em um arquivo
     * temporário na mesma pasta e só então renomeado. Quem lê nunca vê um
     * arquivo parcial.
     */
    public static function write(string $file, string $source): void
    {
        try {
            AtomicPhpFile::write($file, $source);
        } catch (\RuntimeException $exception) {
            throw new ManifestException($exception->getMessage(), 0, $exception);
        }
    }

    /**
     * Carrega o Manifest para uso em produção. Faz apenas verificações baratas
     * (formato, versão do Core, versão do PHP e ambiente). A detecção de
     * arquivos de origem alterados é feita por problems(), no processo de publicação.
     */
    public static function load(string $file, string $environment): Manifest
    {
        $data = self::include($file);
        $meta = $data['meta'];

        $expected = [
            'format' => Manifest::FORMAT,
            'core' => Application::VERSION,
            'php' => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
            'env' => $environment,
        ];

        foreach ($expected as $key => $value) {
            if (($meta[$key] ?? null) !== $value) {
                throw new ManifestException(sprintf(
                    'Manifest incompatível: "%s" é %s, esperado %s. Gere o Manifest novamente.',
                    $key,
                    var_export($meta[$key] ?? null, true),
                    var_export($value, true),
                ));
            }
        }

        return new Manifest(
            $meta,
            $data['config'],
            new CompiledRouter($data['routes'], $data['static'], $data['tree'], $data['names']),
            $data['services'],
            is_array($data['extensions'] ?? null) ? $data['extensions'] : [],
        );
    }

    /**
     * Verificação completa, para o processo de publicação: integridade do
     * conteúdo e arquivos de origem alterados desde a geração. Retorna a
     * lista de problemas (vazia quando está tudo certo).
     *
     * @return list<string>
     */
    public static function problems(string $file, string $basePath): array
    {
        try {
            $data = self::include($file);
        } catch (ManifestException $exception) {
            return [$exception->getMessage()];
        }

        $problems = [];
        $meta = $data['meta'];
        $source = (string) file_get_contents($file);

        if (preg_match(self::HASH_PATTERN, $source, $match) !== 1) {
            $problems[] = 'Hash de integridade ausente.';
        } else {
            $zeroed = preg_replace(self::HASH_PATTERN, "'hash' => '" . self::HASH_PLACEHOLDER . "'", $source, 1) ?? '';

            if (!hash_equals($match[1], self::hash($zeroed))) {
                $problems[] = 'O conteúdo do Manifest não confere com o hash gravado (arquivo alterado ou corrompido).';
            }
        }

        if (($meta['format'] ?? null) !== Manifest::FORMAT) {
            $problems[] = 'Formato do Manifest não suportado.';
        }

        if (($meta['core'] ?? null) !== Application::VERSION) {
            $problems[] = sprintf('Gerado pelo Core %s, mas o Core atual é %s.', var_export($meta['core'] ?? null, true), Application::VERSION);
        }

        if (($meta['php'] ?? null) !== PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION) {
            $problems[] = sprintf('Gerado para o PHP %s, mas o PHP atual é %d.%d.', var_export($meta['php'] ?? null, true), PHP_MAJOR_VERSION, PHP_MINOR_VERSION);
        }

        $root = rtrim($basePath, '/\\') . DIRECTORY_SEPARATOR;

        foreach ((array) ($meta['sources'] ?? []) as $relative => $expectedHash) {
            $path = $root . str_replace('/', DIRECTORY_SEPARATOR, (string) $relative);

            if (!is_file($path)) {
                $problems[] = sprintf('Arquivo de origem removido: %s', $relative);
            } elseif (hash_file('xxh3', $path) !== $expectedHash) {
                $problems[] = sprintf('Arquivo de origem alterado desde a geração: %s', $relative);
            }
        }

        return $problems;
    }

    public static function hash(string $source): string
    {
        return hash('xxh3', $source);
    }

    /**
     * @return array{meta: array<string, mixed>, config: array<string, mixed>, routes: array<int, mixed>, static: array<string, mixed>, tree: array<string, mixed>, names: array<string, int>, services: array<string, \Closure>}
     */
    private static function include(string $file): array
    {
        if (!is_file($file)) {
            throw new ManifestException('Manifest ausente. Gere-o antes de ativar esta versão (bin/manifest.php build).');
        }

        try {
            $data = (static fn (): mixed => include $file)();
        } catch (Throwable $exception) {
            throw new ManifestException('Manifest corrompido ou ilegível: ' . $exception->getMessage(), 0, $exception);
        }

        if (!is_array($data)) {
            throw new ManifestException('Manifest corrompido: o arquivo não retornou um array.');
        }

        foreach (['meta', 'config', 'routes', 'static', 'tree', 'names', 'services'] as $key) {
            if (!isset($data[$key]) || !is_array($data[$key])) {
                throw new ManifestException(sprintf('Manifest corrompido: seção "%s" ausente.', $key));
            }
        }

        /** @var array{meta: array<string, mixed>, config: array<string, mixed>, routes: array<int, mixed>, static: array<string, mixed>, tree: array<string, mixed>, names: array<string, int>, services: array<string, \Closure>} $data */
        return $data;
    }
}
