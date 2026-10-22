<?php

declare(strict_types=1);

namespace Ethernize\Core\Cli\Generator;

use Ethernize\Core\Cli\Arguments;
use Ethernize\Core\Cli\UsageError;

/**
 * `php ethernize make:resource Product`: the files of one feature, from the parts that the installed packages
 * offer (model, migration, service, Pulse components, controller...). It asks for the parts by name, so the
 * result is what you chose and nothing else, and it never adds routes or public endpoints.
 */
final class MakeResourceCommand extends GeneratorCommand
{
    /** @var list<ResourcePart> */
    private array $chosen = [];

    private ?Resource $resource = null;

    /**
     * @param list<ResourcePart> $parts
     */
    public function __construct(string $root, private readonly array $parts)
    {
        parent::__construct($root);
    }

    public function name(): string
    {
        return 'make:resource';
    }

    public function summary(): string
    {
        return 'Create the files of a whole feature (model, migration, service, components...)';
    }

    public function usage(): string
    {
        $lines = [];

        foreach ($this->parts as $part) {
            $lines[] = sprintf('  --%-12s%s', $part->name(), $part->isDefault() ? '(included by default)' : '');
        }

        return implode("\n", [
            'Usage: php ethernize make:resource <Name> [parts] [options]',
            '',
            'Generates one feature from the parts below. <Name> is the singular name of the thing: Product.',
            'Without any part flag, the default parts are generated. With flags, only the parts you name.',
            '',
            'Parts:',
            ...($lines === [] ? ['  (no part is available: install the packages that provide them)'] : $lines),
            '  --all         every part',
            '',
            'Options:',
            '  --fields=LIST   columns as name:type[?], comma separated (default: name:string)',
            '                  types: ' . implode(', ', Fields::TYPES) . '; "?" makes a field nullable',
            '  --table=NAME    table name when the plural of the model is not what you want',
            $this->commonUsage(),
            '',
            'Nothing is registered for you: no routes, no policies. The command prints what is left to do.',
            '',
            'Examples:',
            '  php ethernize make:resource Product',
            '  php ethernize make:resource Product --model --migration --controller --fields=name:string,price_cents:int',
            '  php ethernize make:resource Product --all --split',
        ]);
    }

    protected function options(): array
    {
        $options = ['fields', 'table', 'all'];

        foreach ($this->parts as $part) {
            $options = [...$options, $part->name(), ...$part->options()];
        }

        return array_values(array_unique($options));
    }

    protected function plan(Arguments $arguments, Project $project, Plan $plan): void
    {
        if ($this->parts === []) {
            throw new UsageError('No part is available. Install the packages that provide them (for example ethernize-strata for models and ethernize-pulse for components).');
        }

        ['namespace' => $sub, 'class' => $name] = $this->className($arguments, 'Product');

        $table = $arguments->option('table');

        if ($table !== null && preg_match('/^[a-z][a-z0-9_]*$/', $table) !== 1) {
            throw new UsageError('--table takes a snake_case table name.');
        }

        $this->resource = new Resource($name, $sub, Fields::parse($arguments->option('fields')), $table);
        $named = array_values(array_filter($this->parts, static fn (ResourcePart $part): bool => $arguments->has($part->name())));
        $this->chosen = match (true) {
            $arguments->has('all') => $this->parts,
            $named !== [] => $named,
            default => array_values(array_filter($this->parts, static fn (ResourcePart $part): bool => $part->isDefault())),
        };

        if ($this->chosen === []) {
            throw new UsageError('No part to generate: choose some with --' . implode(', --', array_map(static fn (ResourcePart $part): string => $part->name(), $this->parts)) . '.');
        }

        // The model goes first: the other parts look for it in the plan to use it.
        usort($this->chosen, static fn (ResourcePart $a, ResourcePart $b): int => ($a->name() === 'model' ? 0 : 1) <=> ($b->name() === 'model' ? 0 : 1));

        foreach ($this->chosen as $part) {
            $part->contribute($this->resource, $project, $plan, $arguments);
        }
    }

    protected function afterWrite(Arguments $arguments, Project $project): array
    {
        $lines = [];

        if ($this->resource !== null) {
            foreach ($this->chosen as $part) {
                array_push($lines, ...$part->finish($this->resource, $project, $arguments));
            }
        }

        return $lines;
    }
}
