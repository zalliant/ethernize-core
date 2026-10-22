<?php

declare(strict_types=1);

namespace Ethernize\Core\Cli\Generator;

use Ethernize\Core\Cli\UsageError;

/**
 * The spelling rules shared by every generator, so Product, products, product-form and ProductForm always
 * mean the same thing in a class name, a table, a template and a URL.
 */
final class Names
{
    private function __construct()
    {
    }

    /**
     * A class name with optional sub-namespaces: "Admin/Product" or "Admin\Product".
     *
     * @return array{namespace: list<string>, class: string}
     * @throws UsageError
     */
    public static function parseClass(string $input): array
    {
        $segments = preg_split('~[\\\\/]+~', trim($input, '\\/')) ?: [];

        foreach ($segments as $segment) {
            if (preg_match('/^[A-Z][A-Za-z0-9]*$/', $segment) !== 1) {
                throw new UsageError(sprintf('"%s" is not a valid class name. Use PascalCase letters and digits, for example Product or Admin/ProductForm.', $input));
            }
        }

        $class = (string) array_pop($segments);

        if ($class === '' || self::isReserved($class)) {
            throw new UsageError(sprintf('"%s" cannot be used as a class name.', $input));
        }

        return ['namespace' => $segments, 'class' => $class];
    }

    public static function snake(string $studly): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $studly));
    }

    public static function kebab(string $studly): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '-$0', $studly));
    }

    /**
     * The plural of an English noun in snake_case, by the usual rules (category to categories, box to boxes).
     * Irregular nouns are the rare case: name the table yourself with --table.
     */
    public static function table(string $studly): string
    {
        $snake = self::snake($studly);

        return match (true) {
            preg_match('/[^aeiou]y$/', $snake) === 1 => substr($snake, 0, -1) . 'ies',
            preg_match('/(s|x|z|ch|sh)$/', $snake) === 1 => $snake . 'es',
            default => $snake . 's',
        };
    }

    public static function studlyFromSnake(string $snake): string
    {
        return implode('', array_map('ucfirst', explode('_', $snake)));
    }

    /**
     * "Products" from "Product".
     */
    public static function plural(string $studly): string
    {
        return self::studlyFromSnake(self::table($studly));
    }

    private static function isReserved(string $class): bool
    {
        return in_array(strtolower($class), ['class', 'interface', 'trait', 'enum', 'abstract', 'final', 'static', 'self', 'parent', 'array', 'string', 'int', 'float', 'bool', 'null', 'true', 'false', 'object', 'iterable', 'mixed', 'void', 'never', 'callable', 'new', 'list', 'match', 'fn', 'readonly'], true);
    }
}
