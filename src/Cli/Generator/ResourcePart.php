<?php

declare(strict_types=1);

namespace Ethernize\Core\Cli\Generator;

use Ethernize\Core\Cli\Arguments;

/**
 * One piece of a feature that `make:resource` can generate. Each package offers the parts it knows about
 * (Strata: the model and its migration; Pulse: the components; Core: the controller and the service), and
 * lists them in the "extra.ethernize.resource-parts" section of its composer.json. The command itself knows
 * none of them.
 */
interface ResourcePart
{
    /**
     * The word used to select it: --model, --migration...
     */
    public function name(): string;

    /**
     * Whether it is generated when no part is selected.
     */
    public function isDefault(): bool;

    /**
     * Options this part reads besides its own name, for example "split" or "timestamps".
     *
     * @return list<string>
     */
    public function options(): array;

    /**
     * Adds the part's files to the plan.
     */
    public function contribute(Resource $resource, Project $project, Plan $plan, Arguments $arguments): void;

    /**
     * Runs after the files are written (never with --dry-run). It may finish setup that is not a new file,
     * such as registering a component in a configuration file, and returns lines for the developer: what was
     * done, and what is left (a route to add, a migration to run).
     *
     * @return list<string>
     */
    public function finish(Resource $resource, Project $project, Arguments $arguments): array;
}
