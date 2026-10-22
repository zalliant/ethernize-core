<?php

declare(strict_types=1);

namespace Ethernize\Core\Cli\Generator;

use Ethernize\Core\Cli\Arguments;
use Ethernize\Core\Cli\UsageError;

/**
 * `php ethernize make:controller ProductController`: a controller class with an index action and its page
 * template. It does not register a route: reaching a new page is a decision for the developer.
 */
final class MakeControllerCommand extends GeneratorCommand
{
    public function name(): string
    {
        return 'make:controller';
    }

    public function summary(): string
    {
        return 'Create a controller and its page template';
    }

    public function usage(): string
    {
        return implode("\n", [
            'Usage: php ethernize make:controller <Name> [--api] [--model=Product] [options]',
            '',
            'Creates src/Controllers/<Name>.php with an index action, and (unless --api) the template it renders,',
            'resources/views/pages/<name>/index.weave. No route is added: the command prints the line to add.',
            '',
            '  --api           a controller that answers JSON and has no template',
            '  --model=NAME    list this model in the index action (a class in the Models namespace)',
            $this->commonUsage(),
            '',
            'Examples:',
            '  php ethernize make:controller ProductController',
            '  php ethernize make:controller Admin/ReportController --api',
        ]);
    }

    protected function options(): array
    {
        return ['api', 'model'];
    }

    protected function plan(Arguments $arguments, Project $project, Plan $plan): void
    {
        ['namespace' => $sub, 'class' => $class] = $this->className($arguments, 'ProductController');

        if (!str_ends_with($class, 'Controller') || $class === 'Controller') {
            throw new UsageError(sprintf('Name the controller like ProductController (it ends with "Controller"). Got "%s".', $class));
        }

        $base = substr($class, 0, -strlen('Controller'));
        $url = str_replace('_', '-', Names::table($base));
        $page = 'pages/' . $url . '/index';
        $model = null;

        if ($arguments->has('model')) {
            ['namespace' => $modelSub, 'class' => $modelClass] = Names::parseClass((string) $arguments->option('model'));
            $model = $project->namespaceFor(['Models', ...$modelSub]) . '\\' . $modelClass;
        }

        $folder = ['Controllers', ...$sub];
        $plan->add($project->sourcePath([...$folder, $class]), Stubs::controller($project, $folder, $class, $page, ucfirst(str_replace('-', ' ', $url)), $url, $model, $arguments->has('api')));

        if (!$arguments->has('api')) {
            $plan->add($project->appPath('resources/views/' . $page . '.weave'), Stubs::page(ucfirst(str_replace('-', ' ', $url)), $model !== null, null));
        }

        $plan->note(sprintf("Add a route when it is ready (routes/web.php):\n  Web::on('/%s')->get([%s::class, 'index']);", $url, $class));
    }
}
