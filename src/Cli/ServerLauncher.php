<?php

declare(strict_types=1);

namespace Ethernize\Core\Cli;

/**
 * Starts the development web server and waits for it. Replaceable so the `run` command can be
 * tested without opening a port.
 */
interface ServerLauncher
{
    /**
     * @param list<string>          $command
     * @param array<string, string> $environment the complete environment of the server process
     * @param int                   $port        the port the server is expected to listen on
     * @return int exit code: 0 when stopped by the person (Ctrl+C), otherwise the server's own
     */
    public function launch(array $command, string $directory, array $environment, int $port, Output $output): int;
}
