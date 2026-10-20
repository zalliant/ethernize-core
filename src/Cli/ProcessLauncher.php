<?php

declare(strict_types=1);

namespace Ethernize\Core\Cli;

/**
 * Runs the server as a child process that shares this terminal, and stops it when the person
 * presses Ctrl+C. On Windows the process tree is ended with taskkill, because the PHP server is a
 * child of whatever started it; elsewhere the child is signalled.
 */
final class ProcessLauncher implements ServerLauncher
{
    /** STATUS_CONTROL_C_EXIT, the exit status of a Windows process ended by Ctrl+C. */
    private const CTRL_C_EXIT = 3221225786;

    public function launch(array $command, string $directory, array $environment, int $port, Output $output): int
    {
        $stopRequested = false;
        $request = static function () use (&$stopRequested): void {
            $stopRequested = true;
        };

        if (PHP_OS_FAMILY === 'Windows' && function_exists('sapi_windows_set_ctrl_handler')) {
            sapi_windows_set_ctrl_handler(static function (int $event) use ($request): void {
                if ($event === PHP_WINDOWS_EVENT_CTRL_C || $event === PHP_WINDOWS_EVENT_CTRL_BREAK) {
                    $request();
                }
            });
        } elseif (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGINT, static fn () => $request());
            pcntl_signal(SIGTERM, static fn () => $request());
        }

        $process = proc_open($command, [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes, $directory, $environment);

        if (!is_resource($process)) {
            $output->error('Could not start the PHP server.');

            return 1;
        }

        $announced = false;

        while (!$stopRequested) {
            $status = proc_get_status($process);

            if (!$status['running']) {
                proc_close($process);

                // Ctrl+C reaches the server too, and it may end before this process has seen the event.
                // It then reports the status for "ended by Ctrl+C", which is a normal stop.
                if (!$stopRequested) {
                    usleep(300000);
                }

                if ($stopRequested || in_array($status['exitcode'], [self::CTRL_C_EXIT, 130, 143], true)) {
                    $stopRequested = true;
                    $output->line();
                    $output->line('Stopped.');

                    return 0;
                }

                // The server ended by itself: before it ever listened this is a failure to start.
                $output->error($announced ? sprintf('The server stopped (exit code %d).', $status['exitcode']) : sprintf('The server could not start (exit code %d). Is the port free?', $status['exitcode']));

                return $status['exitcode'] > 0 ? $status['exitcode'] : 1;
            }

            if (!$announced && $this->listening($port)) {
                $announced = true;
            }

            usleep(100000);
        }

        $this->stop($process);
        $output->line();
        $output->line('Stopped.');

        return 0;
    }

    private function listening(int $port): bool
    {
        $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);

        if ($socket === false) {
            return false;
        }

        fclose($socket);

        return true;
    }

    /**
     * @param resource $process
     */
    private function stop($process): void
    {
        $status = proc_get_status($process);

        if ($status['running']) {
            if (PHP_OS_FAMILY === 'Windows') {
                exec('taskkill /T /F /PID ' . (int) $status['pid'] . ' 2>&1');
            } else {
                proc_terminate($process);
            }
        }

        proc_close($process);
    }
}
