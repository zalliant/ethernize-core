<?php

declare(strict_types=1);

namespace Ethernize\Core\Cli;

/**
 * A command of the `ethernize` launcher. Register it with Console::register().
 */
interface Command
{
    /**
     * The word typed after `php ethernize`, for example "run".
     */
    public function name(): string;

    /**
     * One line for the command list.
     */
    public function summary(): string;

    /**
     * Full usage text shown by `php ethernize help <name>`.
     */
    public function usage(): string;

    /**
     * @return int process exit code: 0 success, 1 failure while running, 2 wrong usage
     */
    public function run(Arguments $arguments, Output $output): int;
}
