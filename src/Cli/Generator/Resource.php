<?php

declare(strict_types=1);

namespace Ethernize\Core\Cli\Generator;

/**
 * The feature `make:resource` is generating, with every name derived once so that all parts agree:
 * for "Product": model Product, table products, ProductService, ProductController, the Pulse list Products and
 * the form ProductForm, the URL segment "products".
 */
final class Resource
{
    public readonly string $plural;
    public readonly string $table;
    public readonly string $kebabPlural;

    /**
     * @param list<string> $namespace sub-namespace segments given with the name (Admin/Product gives ['Admin'])
     */
    public function __construct(
        public readonly string $name,
        public readonly array $namespace,
        public readonly Fields $fields,
        ?string $table = null,
    ) {
        $this->table = $table ?? Names::table($name);
        $this->plural = Names::plural($name);
        $this->kebabPlural = str_replace('_', '-', $this->table);
    }

    /**
     * A folder of src/ for a kind of class, with the sub-namespace of the resource appended:
     * folder('Models') is ['Models'] or ['Models', 'Admin'].
     *
     * @return list<string>
     */
    public function folder(string $kind): array
    {
        return [$kind, ...$this->namespace];
    }
}
