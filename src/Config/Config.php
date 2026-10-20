<?php

declare(strict_types=1);

namespace Ethernize\Core\Config;

use Closure;
use InvalidArgumentException;

/**
 * Configuração imutável acessada por chaves com pontos ("app.debug").
 */
final class Config
{
    /**
     * @param array<string, mixed> $items
     */
    public function __construct(private readonly array $items = [])
    {
    }

    /**
     * Carrega cada arquivo PHP de $directory como uma seção com o nome do
     * arquivo. Um arquivo pode retornar um array ou uma função que recebe o
     * Environment. Se existir $directory/<ambiente>/, seus arquivos são
     * mesclados por cima da configuração base.
     */
    public static function fromDirectory(string $directory, Environment $environment): self
    {
        $items = self::readSections($directory, $environment);
        $name = $environment->get('APP_ENV', 'production') ?? 'production';

        if (preg_match('/^[a-z0-9_-]+$/', $name) !== 1) {
            throw new InvalidArgumentException('APP_ENV deve conter apenas letras minúsculas, números, hífen e sublinhado.');
        }

        $overrides = $directory . DIRECTORY_SEPARATOR . $name;

        if (is_dir($overrides)) {
            $items = array_replace_recursive($items, self::readSections($overrides, $environment));
        }

        return new self($items);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->items;

        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }

            $value = $value[$segment];
        }

        return $value;
    }

    public function has(string $key): bool
    {
        $marker = new \stdClass();

        return $this->get($key, $marker) !== $marker;
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->items;
    }

    /**
     * @return array<string, mixed>
     */
    private static function readSections(string $directory, Environment $environment): array
    {
        $items = [];

        // scandir em vez de glob: caracteres como [ ] no caminho não podem alterar a busca.
        $names = is_dir($directory) ? (scandir($directory) ?: []) : [];

        foreach ($names as $name) {
            $file = $directory . DIRECTORY_SEPARATOR . $name;

            if (!str_ends_with($name, '.php') || !is_file($file)) {
                continue;
            }

            $section = require $file;

            if ($section instanceof Closure) {
                $section = $section($environment);
            }

            if (!is_array($section)) {
                throw new InvalidArgumentException(sprintf('O arquivo de configuração "%s" deve retornar um array.', $file));
            }

            $items[basename($file, '.php')] = $section;
        }

        return $items;
    }
}
