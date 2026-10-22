<?php

declare(strict_types=1);

namespace Ethernize\Core\Cli\Generator;

use Ethernize\Core\Cli\Arguments;

/**
 * make:resource --controller: a controller with an index action and its page template. No route is added.
 */
final class ControllerPart implements ResourcePart
{
    public function name(): string
    {
        return 'controller';
    }

    public function isDefault(): bool
    {
        return false;
    }

    public function options(): array
    {
        return ['api'];
    }

    public function contribute(Resource $resource, Project $project, Plan $plan, Arguments $arguments): void
    {
        $model = CoreParts::model($resource, $project, $plan);
        $class = $resource->name . 'Controller';
        $folder = $resource->folder('Controllers');
        $title = ucfirst(str_replace('-', ' ', $resource->kebabPlural));
        $page = 'pages/' . $resource->kebabPlural . '/index';
        $plan->add($project->sourcePath([...$folder, $class]), Stubs::controller($project, $folder, $class, $page, $title, $resource->kebabPlural, $model, $arguments->has('api')));

        if (!$arguments->has('api')) {
            $label = null;

            foreach ($resource->fields->fields as $field) {
                if (in_array($field['type'], ['string', 'text'], true)) {
                    $label = $field['name'];
                    break;
                }
            }

            $plan->add($project->appPath('resources/views/' . $page . '.weave'), Stubs::page($title, $model !== null, $label));
        }
    }

    public function finish(Resource $resource, Project $project, Arguments $arguments): array
    {
        return [sprintf("Route (routes/web.php), added by you when it should be reachable:\n  Web::on('/%s')->get([%sController::class, 'index']);", $resource->kebabPlural, $resource->name)];
    }
}
