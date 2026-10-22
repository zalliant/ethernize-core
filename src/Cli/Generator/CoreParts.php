<?php

declare(strict_types=1);

namespace Ethernize\Core\Cli\Generator;

use Ethernize\Core\Cli\Arguments;

/**
 * The parts of `make:resource` that belong to Core: the controller with its page, and the service.
 * They use the model of the feature when the same run (or the project) has one.
 */
final class CoreParts
{
    private function __construct()
    {
    }

    /**
     * The model class of the resource if it exists in the project or in the plan.
     */
    public static function model(Resource $resource, Project $project, Plan $plan): ?string
    {
        $path = $project->sourcePath([...$resource->folder('Models'), $resource->name]);

        if ($plan->contents($path) === null && !is_file($project->root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path))) {
            return null;
        }

        return $project->namespaceFor($resource->folder('Models')) . '\\' . $resource->name;
    }
}
