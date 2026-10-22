<?php

declare(strict_types=1);

namespace Ethernize\Core\Cli\Generator;

use Ethernize\Core\Cli\Arguments;
use Ethernize\Core\Cli\Command;
use Ethernize\Core\Cli\Output;
use Ethernize\Core\Cli\UsageError;

/**
 * Base of the `make:*` commands. A command only describes what to create (plan()); this class takes care
 * of what every generator must do the same way:
 *
 *  - finds the application and its namespace (--app picks one when the project has several);
 *  - checks every target before writing anything, and refuses to replace a file unless --force is given;
 *  - --dry-run prints what would be created and writes nothing;
 *  - prints what it created, and what is left for the developer to do.
 *
 * Packages add their own generators by implementing this class; see the Console for how they are found.
 */
abstract class GeneratorCommand implements Command
{
    public function __construct(protected readonly string $root)
    {
    }

    /**
     * The options this generator understands besides the common ones.
     *
     * @return list<string>
     */
    protected function options(): array
    {
        return [];
    }

    /**
     * Describes the files to create. Throw UsageError for a mistake in the arguments.
     *
     * @throws UsageError
     */
    abstract protected function plan(Arguments $arguments, Project $project, Plan $plan): void;

    /**
     * Runs after the files are written; return lines for the developer, or nothing.
     *
     * @return list<string>
     */
    protected function afterWrite(Arguments $arguments, Project $project): array
    {
        return [];
    }

    final public function run(Arguments $arguments, Output $output): int
    {
        $arguments->allowOnly(['app', 'force', 'dry-run', ...$this->options()]);

        $project = Project::find($this->root, $arguments->option('app'));
        $plan = new Plan($this->root);
        $this->plan($arguments, $project, $plan);

        if ($plan->paths() === []) {
            throw new UsageError('Nothing to generate.');
        }

        if ($arguments->has('dry-run')) {
            foreach ($plan->paths() as $path) {
                $output->line(sprintf('%s  %s', in_array($path, $plan->conflicts(), true) ? 'exists ' : 'would create', $path));
            }

            return 0;
        }

        $plan->write($arguments->has('force'));

        foreach ($plan->paths() as $path) {
            $output->line('created  ' . $path);
        }

        foreach ([...$plan->notes(), ...$this->afterWrite($arguments, $project)] as $line) {
            $output->line($line);
        }

        return 0;
    }

    /**
     * The one required argument, validated as a class name.
     *
     * @return array{namespace: list<string>, class: string}
     */
    protected function className(Arguments $arguments, string $example): array
    {
        if (count($arguments->positional) !== 1) {
            throw new UsageError(sprintf('Give one name. Example: php ethernize %s %s', $this->name(), $example));
        }

        return Names::parseClass($arguments->positional[0]);
    }

    protected function commonUsage(): string
    {
        return implode("\n", [
            '  --app=NAME   the application in apps/ to generate into (default: app, or the only one)',
            '  --force      replace files that already exist (without it, nothing is written when one exists)',
            '  --dry-run    show the files that would be created and write nothing',
        ]);
    }
}
