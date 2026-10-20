<?php

declare(strict_types=1);

namespace Ethernize\Core\Config;

/**
 * Variáveis de ambiente de uma instância da aplicação.
 *
 * Os valores ficam isolados no objeto: nada é escrito em $_ENV, $_SERVER ou
 * putenv(), então duas aplicações no mesmo processo não se influenciam.
 */
final class Environment
{
    /**
     * @param array<string, string> $values
     */
    public function __construct(private readonly array $values = [])
    {
    }

    /**
     * Lê o arquivo .env de $basePath (se existir). Variáveis reais do processo
     * têm prioridade sobre o arquivo.
     */
    public static function load(string $basePath): self
    {
        $values = [];
        $file = rtrim($basePath, '/\\') . DIRECTORY_SEPARATOR . '.env';

        if (is_file($file)) {
            $values = self::parse((string) file_get_contents($file));
        }

        foreach (getenv() as $key => $value) {
            if (is_string($value)) {
                $values[$key] = $value;
            }
        }

        return new self($values);
    }

    /**
     * @return array<string, string>
     */
    public static function parse(string $contents): array
    {
        $values = [];

        foreach (preg_split('/\R/', $contents) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || $line[0] === '#') {
                continue;
            }

            if (str_starts_with($line, 'export ')) {
                $line = ltrim(substr($line, 7));
            }

            $position = strpos($line, '=');

            if ($position === false) {
                continue;
            }

            $key = trim(substr($line, 0, $position));
            $value = trim(substr($line, $position + 1));

            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key) !== 1) {
                continue;
            }

            if (preg_match('/^(["\'])(.*)\1/', $value, $matches) === 1) {
                $value = $matches[2];
            } else {
                $value = trim((string) preg_replace('/\s+#.*$/', '', $value));
            }

            $values[$key] = $value;
        }

        return $values;
    }

    public function get(string $key, ?string $default = null): ?string
    {
        return $this->values[$key] ?? $default;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->values[$key] ?? null;

        if ($value === null) {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
    }
}
