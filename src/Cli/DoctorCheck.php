<?php

declare(strict_types=1);

namespace Ethernize\Core\Cli;

use Ethernize\Core\Application;
use Ethernize\Core\Cli\Generator\Project;

/**
 * A check a module adds to `php ethernize doctor`. List the class in the "doctor-checks" part of
 * "extra.ethernize" in the package's composer.json. It receives the booted application; it must not change
 * anything, open a database connection or print a secret.
 */
interface DoctorCheck
{
    /**
     * @return list<array{0: 'ok'|'warn'|'FAIL', 1: string, 2?: string|null}> level, what was found, what to do about it
     */
    public function run(Application $app, Project $project): array;
}
