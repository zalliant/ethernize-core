<?php

declare(strict_types=1);

namespace Ethernize\Core\Cli\Generator;

use Ethernize\Core\Cli\UsageError;

/**
 * The columns of a feature as written on the command line: --fields=name:string,price_cents:int,active:bool,note:text?
 * Each generator turns the same description into its own code (a model property, a migration column, a form
 * input), so they always agree.
 *
 * Types: string, text, int, bigint, float, bool, date, datetime, json. A trailing "?" makes the field nullable.
 */
final class Fields
{
    public const TYPES = ['string', 'text', 'int', 'bigint', 'float', 'bool', 'date', 'datetime', 'json'];
    private const RESERVED = ['id', 'created_at', 'updated_at', 'deleted_at', 'version', 'connection', 'exists', 'original', 'relations'];

    /**
     * @param list<array{name: string, type: string, nullable: bool}> $fields
     */
    private function __construct(public readonly array $fields)
    {
    }

    /**
     * No fields at all.
     */
    public static function none(): self
    {
        return new self([]);
    }

    /**
     * @throws UsageError
     */
    public static function parse(?string $spec): self
    {
        if ($spec === null || trim($spec) === '') {
            return new self([['name' => 'name', 'type' => 'string', 'nullable' => false]]);
        }

        $fields = [];

        foreach (explode(',', $spec) as $part) {
            if (preg_match('/^([a-z][a-z0-9_]{0,40}):([a-z]+)(\?)?$/', trim($part), $m) !== 1 || !in_array($m[2], self::TYPES, true)) {
                throw new UsageError(sprintf('"%s" is not a field. Write name:type, with type one of: %s. Example: --fields=name:string,price_cents:int,note:text?', trim($part), implode(', ', self::TYPES)));
            }

            if (in_array($m[1], self::RESERVED, true)) {
                throw new UsageError(sprintf('"%s" is managed by the model; do not list it in --fields.', $m[1]));
            }

            if (isset($fields[$m[1]])) {
                throw new UsageError(sprintf('The field "%s" is listed twice.', $m[1]));
            }

            $fields[$m[1]] = ['name' => $m[1], 'type' => $m[2], 'nullable' => ($m[3] ?? '') === '?'];
        }

        return new self(array_values($fields));
    }
}
