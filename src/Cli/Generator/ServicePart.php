<?php

declare(strict_types=1);

namespace Ethernize\Core\Cli\Generator;

use Ethernize\Core\Cli\Arguments;

/**
 * make:resource --service: a service class, with create() and find() when the feature has a model.
 */
final class ServicePart implements ResourcePart
{
    public function name(): string
    {
        return 'service';
    }

    public function isDefault(): bool
    {
        return true;
    }

    public function options(): array
    {
        return [];
    }

    public function contribute(Resource $resource, Project $project, Plan $plan, Arguments $arguments): void
    {
        $class = $resource->name . 'Service';
        $folder = $resource->folder('Services');
        $plan->add($project->sourcePath([...$folder, $class]), Stubs::service($project, $folder, $class, CoreParts::model($resource, $project, $plan)));
    }

    public function finish(Resource $resource, Project $project, Arguments $arguments): array
    {
        return [];
    }
}
