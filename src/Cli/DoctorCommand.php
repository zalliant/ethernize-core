<?php

declare(strict_types=1);

namespace Ethernize\Core\Cli;

use Ethernize\Core\Application;
use Ethernize\Core\Cli\Generator\Project;
use Ethernize\Core\Http\Diagnosable;
use Ethernize\Core\Manifest\ManifestFile;
use Throwable;

/**
 * `php ethernize doctor`: looks at an application the way a new teammate would, and says what is wrong and what
 * to do about it. It changes nothing, opens no database connection and prints no secret.
 */
final class DoctorCommand implements Command
{
    private const EXTENSIONS = ['mbstring', 'ctype', 'json', 'hash', 'filter', 'pdo'];

    /** Messages of failures people meet often, with what to do. */
    private const KNOWN = [
        'PULSE_KEY' => 'Pulse signs its state with a key. Outside local development set the environment variable PULSE_KEY (generate one: php -r "echo \'base64:\' . base64_encode(random_bytes(32)), PHP_EOL;").',
        'database.default' => 'config/database.php must name a connection that exists under "connections".',
        'shield.session.secure' => 'Secure cookies cannot be switched off outside local development. Use HTTPS, or APP_ENV=local while developing.',
    ];

    /**
     * @param list<DoctorCheck> $checks the checks that installed modules add
     */
    public function __construct(private readonly string $root, private readonly array $checks = [])
    {
    }

    public function name(): string
    {
        return 'doctor';
    }

    public function summary(): string
    {
        return 'Check an application and explain what is wrong';
    }

    public function usage(): string
    {
        return implode("\n", [
            'Usage: php ethernize doctor [--app=NAME]',
            '',
            'Checks PHP and its extensions, the autoloader, the environment, that the application boots, the folders it',
            'writes to, the Manifest and the database configuration. It edits no file of yours and opens no connection;',
            'booting the application can still create its own cache folders under var/.',
            'Exit code 1 when something must be fixed, 0 otherwise (warnings do not fail).',
        ]);
    }

    public function run(Arguments $arguments, Output $output): int
    {
        $arguments->allowOnly(['app']);
        $project = Project::find($this->root, $arguments->option('app'));
        $failures = 0;
        $warnings = 0;
        $report = static function (string $level, string $what, ?string $hint = null) use ($output, &$failures, &$warnings): void {
            $output->line(sprintf('  %-4s %s', $level, $what));

            if ($hint !== null) {
                $output->line('       -> ' . $hint);
            }

            $failures += $level === 'FAIL' ? 1 : 0;
            $warnings += $level === 'warn' ? 1 : 0;
        };

        $output->line(sprintf('Application "%s" (%s)', $project->name, 'apps/' . $project->name));

        // PHP
        PHP_VERSION_ID >= 80200
            ? $report('ok', 'PHP ' . PHP_VERSION)
            : $report('FAIL', 'PHP ' . PHP_VERSION . ' is too old', 'Ethernize needs PHP 8.2 or newer.');

        $missing = array_values(array_filter(self::EXTENSIONS, static fn (string $extension): bool => !extension_loaded($extension)));
        $missing === []
            ? $report('ok', 'PHP extensions: ' . implode(', ', self::EXTENSIONS))
            : $report('FAIL', 'missing PHP extensions: ' . implode(', ', $missing), 'Enable them in php.ini (extension=' . $missing[0] . ').');

        // Structure
        foreach (['public/index.php' => true, 'config' => true, 'routes/web.php' => false] as $path => $required) {
            $exists = file_exists($project->directory . '/' . $path);
            $exists
                ? $report('ok', 'apps/' . $project->name . '/' . $path)
                : $report($required ? 'FAIL' : 'warn', 'apps/' . $project->name . '/' . $path . ' is missing', $required ? 'This is not a complete application folder.' : 'Without it the application has no routes.');
        }

        try {
            $project->namespaceFor([]);
            $report('ok', 'composer.json maps a namespace to apps/' . $project->name . '/src/');
        } catch (UsageError $error) {
            $report('warn', 'no PSR-4 namespace for apps/' . $project->name . '/src/', 'Generators and autoloading need it: add "App\\\\": "apps/' . $project->name . '/src/" to autoload.psr-4 in composer.json, then composer dump-autoload.');
        }

        is_file($this->root . '/vendor/autoload.php')
            ? $report('ok', 'vendor/autoload.php')
            : $report('FAIL', 'vendor/autoload.php is missing', 'Run composer install.');

        // Boot
        $app = null;

        try {
            $app = Application::create($project->directory);
            $app->boot();
            $report('ok', sprintf('the application boots (environment "%s", debug %s)', $app->environment(), $app->isDebug() ? 'on' : 'off'));
        } catch (Throwable $error) {
            $app = null;
            $hint = $error instanceof Diagnosable ? $error->hint() : null;

            foreach (self::KNOWN as $needle => $known) {
                if ($hint === null && str_contains($error->getMessage(), $needle)) {
                    $hint = $known;
                }
            }

            $report('FAIL', 'the application does not boot: ' . $error->getMessage(), $hint ?? 'The message above is the cause; it was raised at ' . basename($error->getFile()) . ':' . $error->getLine() . '.');
        }

        if ($app !== null) {
            if ($app->isDebug() && !in_array($app->environment(), ['local', 'development', 'testing'], true)) {
                $report('FAIL', 'debug is on in the "' . $app->environment() . '" environment', 'It shows stack traces to visitors. Set APP_DEBUG=false.');
            }

            if (!in_array($app->environment(), ['local', 'development', 'testing'], true) && !$app->isCompiled()) {
                is_file(ManifestFile::path($project->directory))
                    ? $report('warn', 'a Manifest exists but this process does not use it: routes and services are resolved on every request', 'Set APP_MANIFEST=required in the environment of the web server (not in .env) to serve from the Manifest.')
                    : $report('warn', 'production without a Manifest: routes and services are resolved on every request', 'Run php ethernize build before publishing.');
            }

            foreach ($this->checks as $check) {
                try {
                    foreach ($check->run($app, $project) as $row) {
                        $report($row[0], $row[1], $row[2] ?? null);
                    }
                } catch (Throwable $error) {
                    $report('FAIL', 'a check of ' . $check::class . ' could not run: ' . $error->getMessage());
                }
            }

            $this->manifest($project, $app, $report);
        }

        // Folders the application writes to
        $var = $project->directory . '/var';
        is_dir($var) && is_writable($var)
            ? $report('ok', 'apps/' . $project->name . '/var is writable')
            : $report('FAIL', 'apps/' . $project->name . '/var is missing or not writable', 'Create it and let the web server user write to it: caches, sessions and revisions live here.');

        if ($app !== null && !in_array($app->environment(), ['local', 'development', 'testing'], true) && is_writable($project->directory . '/public/index.php')) {
            $report('warn', 'public/index.php is writable by this user', 'In production make the code read-only for the web server user; only var/ needs to be writable.');
        }

        if (!is_file($project->directory . '/.env') && is_file($project->directory . '/.env.example')) {
            $report('warn', 'no .env file', 'Copy .env.example to .env, or set the variables in the environment of the server.');
        }

        $output->line();
        $output->line($failures === 0 ? sprintf('No problems found%s.', $warnings > 0 ? sprintf(' (%d warning%s)', $warnings, $warnings === 1 ? '' : 's') : '') : sprintf('%d problem%s to fix.', $failures, $failures === 1 ? '' : 's'));

        return $failures === 0 ? 0 : 1;
    }

    /**
     * @param callable(string, string, ?string=): void $report
     */
    private function manifest(Project $project, Application $app, callable $report): void
    {
        $file = ManifestFile::path($project->directory);

        if (!is_file($file)) {
            $report('ok', 'no Manifest (templates and routes compile on demand: right for development)');

            return;
        }

        $problems = ManifestFile::problems($file, $project->directory);

        $problems === []
            ? $report('ok', 'the Manifest is intact and up to date')
            : $report('warn', 'the Manifest is out of date: ' . $problems[0] . (count($problems) > 1 ? sprintf(' (and %d more)', count($problems) - 1) : ''), 'Run php ethernize build. While it exists and APP_MANIFEST allows it, the application serves the old one.');
    }
}
