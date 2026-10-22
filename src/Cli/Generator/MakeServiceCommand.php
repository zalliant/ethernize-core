<?php

declare(strict_types=1);

namespace Ethernize\Core\Cli\Generator;

use Ethernize\Core\Cli\Arguments;
use Ethernize\Core\Cli\UsageError;

/**
 * `php ethernize make:service ProductService`: a class for business rules, built by the container.
 */
final class MakeServiceCommand extends GeneratorCommand
{
    public function name(): string
    {
        return 'make:service';
    }

    public function summary(): string
    {
        return 'Create a service class';
    }

    public function usage(): string
    {
        return implode("\n", [
            'Usage: php ethernize make:service <Name> [--model=Product] [options]',
            '',
            'Creates src/Services/<Name>.php. With --model the service gets create() and find() for that model.',
            '',
            '  --model=NAME    a class in the Models namespace the service works with',
            $this->commonUsage(),
            '',
            'Example:',
            '  php ethernize make:service ProductService --model=Product',
        ]);
    }

    protected function options(): array
    {
        return ['model'];
    }

    protected function plan(Arguments $arguments, Project $project, Plan $plan): void
    {
        ['namespace' => $sub, 'class' => $class] = $this->className($arguments, 'ProductService');

        if ($class === 'Service' || !str_ends_with($class, 'Service')) {
            throw new UsageError(sprintf('Name the service like ProductService (it ends with "Service"). Got "%s".', $class));
        }

        $model = null;

        if ($arguments->has('model')) {
            ['namespace' => $modelSub, 'class' => $modelClass] = Names::parseClass((string) $arguments->option('model'));
            $model = $project->namespaceFor(['Models', ...$modelSub]) . '\\' . $modelClass;
        }

        $folder = ['Services', ...$sub];
        $plan->add($project->sourcePath([...$folder, $class]), Stubs::service($project, $folder, $class, $model));
    }
}
