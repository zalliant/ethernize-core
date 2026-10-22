<?php

declare(strict_types=1);

namespace Ethernize\Core\Cli;

/**
 * `php ethernize run [application] [--port=N]`: starts the development server of one application of
 * the project, the way bin/serve-local.ps1 does for development: PHP's built-in server on 127.0.0.1,
 * the application's own public folder as the document root and resources/dev-router.php as the router
 * (real files of the public folder are served as they are; everything else reaches public/index.php), APP_ENV=local, APP_DEBUG=true and no Manifest.
 *
 * Each application gets only its own folder and its own APP_URL: running two at once (on different
 * ports) shares nothing.
 */
final class RunCommand implements Command
{
    private const HOST = '127.0.0.1';

    /** Ports used when --port is not given. Other applications start at 8000. */
    private const DEFAULT_PORTS = ['app' => 8080, 'website' => 8081];
    private const FALLBACK_PORT = 8000;
    private const DEFAULT_APPLICATION = 'app';

    public function __construct(
        private readonly string $root,
        private readonly string $version = '',
        private readonly ?ServerLauncher $launcher = null,
    ) {
    }

    public function name(): string
    {
        return 'run';
    }

    public function summary(): string
    {
        return 'Start the development server of an application';
    }

    public function usage(): string
    {
        $applications = array_keys((new ApplicationLocator($this->root))->all());

        return implode("\n", [
            'Usage: php ethernize run [application] [--port=PORT]',
            '',
            'Starts the development server of an application on http://127.0.0.1:PORT and keeps',
            'running until you press Ctrl+C.',
            '',
            '  application   a folder inside apps/ with a public/index.php (default: the only one, or app)',
            '  --port=PORT   the port to listen on (default: 8080 for app, 8081 for website, 8000 for any other)',
            '',
            'Applications found in this project: ' . ($applications === [] ? 'none' : implode(', ', $applications)),
            '',
            'Examples:',
            '  php ethernize run',
            '  php ethernize run website',
            '  php ethernize run website --port=9000',
        ]);
    }

    public function run(Arguments $arguments, Output $output): int
    {
        $arguments->allowOnly(['port']);

        if (count($arguments->positional) > 1) {
            throw new UsageError('Give at most one application name. Example: php ethernize run website');
        }

        $locator = new ApplicationLocator($this->root);
        $applications = $locator->all();
        $name = $arguments->positional[0] ?? $this->defaultApplication($applications);
        $path = $applications[$name] ?? null;

        if ($path === null) {
            throw new UsageError(sprintf(
                'There is no application "%s" in this project. %s',
                $name,
                $applications === [] ? 'No folder in apps/ has a public/index.php.' : 'Available: ' . implode(', ', array_keys($applications)) . '.',
            ));
        }

        $port = $this->port($arguments->option('port'), $name);

        if (!$this->isFree($port)) {
            $output->error(sprintf('Port %d is already in use on %s.', $port, self::HOST));
            $output->error('Something is listening there: maybe this application is already running in another terminal.');
            $output->error(sprintf('  Use another port:      php ethernize run %s --port=%d', $name, $this->suggestPort($port)));
            $output->error(PHP_OS_FAMILY === 'Windows' ? sprintf('  See who is using it:   netstat -ano | findstr :%d', $port) : sprintf('  See who is using it:   lsof -i :%d', $port));

            return 1;
        }

        $url = sprintf('http://%s:%d', self::HOST, $port);
        $output->line(sprintf('Ethernize%s', $this->version !== '' ? ' ' . $this->version : ''));
        $output->line(sprintf('  Application  %s', ucfirst($name)));
        $output->line(sprintf('  Environment  %s', 'local (debug on)'));
        $output->line(sprintf('  URL          %s', $url));
        $output->line();
        $output->line('Press Ctrl+C to stop.');
        $output->line();

        $public = $path . DIRECTORY_SEPARATOR . 'public';
        $command = [PHP_BINARY, '-S', self::HOST . ':' . $port, '-t', $public, dirname(__DIR__, 2) . '/resources/dev-router.php'];

        return ($this->launcher ?? new ProcessLauncher())->launch($command, $this->root, $this->environment($url), $port, $output);
    }

    /**
     * The environment of the server: this process's, with the development settings of the application.
     * A Manifest from an earlier production run is never used in development.
     *
     * @return array<string, string>
     */
    private function environment(string $url): array
    {
        $environment = [];

        foreach (getenv() as $key => $value) {
            if (is_string($value)) {
                $environment[$key] = $value;
            }
        }

        unset($environment['APP_MANIFEST']);

        return ['APP_URL' => $url, 'APP_ENV' => 'local', 'APP_DEBUG' => 'true'] + $environment;
    }

    /**
     * @param array<string, string> $applications
     */
    private function defaultApplication(array $applications): string
    {
        if (isset($applications[self::DEFAULT_APPLICATION]) || count($applications) !== 1) {
            return self::DEFAULT_APPLICATION;
        }

        return (string) array_key_first($applications);
    }

    private function port(?string $given, string $application): int
    {
        if ($given === null) {
            return self::DEFAULT_PORTS[$application] ?? self::FALLBACK_PORT;
        }

        if (preg_match('/^[0-9]{1,5}$/', $given) !== 1 || (int) $given < 1 || (int) $given > 65535) {
            throw new UsageError(sprintf('"%s" is not a valid port. Use a number from 1 to 65535, for example --port=9000.', $given));
        }

        return (int) $given;
    }

    /**
     * A port is taken if something accepts connections on it, or if it cannot be bound.
     */
    private function isFree(int $port): bool
    {
        $connection = @fsockopen(self::HOST, $port, $errno, $errstr, 0.3);

        if ($connection !== false) {
            fclose($connection);

            return false;
        }

        $server = @stream_socket_server(sprintf('tcp://%s:%d', self::HOST, $port), $errno, $errstr);

        if ($server === false) {
            return false;
        }

        fclose($server);

        return true;
    }

    private function suggestPort(int $port): int
    {
        for ($candidate = $port + 1; $candidate <= min($port + 20, 65535); $candidate++) {
            if ($this->isFree($candidate)) {
                return $candidate;
            }
        }

        return min($port + 1, 65535);
    }
}
