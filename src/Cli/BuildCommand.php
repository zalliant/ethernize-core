<?php

declare(strict_types=1);

namespace Ethernize\Core\Cli;

use Ethernize\Core\Cli\Generator\Project;
use Ethernize\Core\Manifest\ManifestCompiler;
use Ethernize\Core\Manifest\ManifestException;
use Ethernize\Core\Manifest\ManifestFile;

/**
 * `php ethernize build`: prepares an application for production. It builds the Manifest (routes, services and
 * configuration) and, through the modules that contribute to it, compiles every template, including the
 * templates inside Pulse single-file components. Nothing is compiled or scanned at request time afterwards.
 */
final class BuildCommand implements Command
{
    /** What to do about the build failures people meet most. */
    private const HINTS = [
        'PULSE_KEY' => 'the build checks the Pulse key like production does: put it in the environment variable PULSE_KEY when you run the build (generate one with php -r "echo \'base64:\' . base64_encode(random_bytes(32)), PHP_EOL;"). The key is read from the environment and is never written to the Manifest.',
        'Closure' => 'routes must use controller classes ([Controller::class, \'method\']) instead of closures, so they can be compiled.',
        'template(s)' => 'the lines above give the template, line and column; fix those and build again.',
    ];

    public function __construct(private readonly string $root)
    {
    }

    public function name(): string
    {
        return 'build';
    }

    public function summary(): string
    {
        return 'Build the Manifest and compile every template for production';
    }

    public function usage(): string
    {
        return implode("\n", [
            'Usage: php ethernize build [--check] [--clear] [--app=NAME]',
            '',
            'Builds apps/<name>/var/cache/ethernize/manifest.php and compiles all Weave templates (Pulse components',
            'included) into var/cache/weave. Run it when you publish, then reload PHP so OPcache sees the new files.',
            '',
            '  --check      only verify that the Manifest is intact and up to date',
            '  --clear      remove the Manifest (the application goes back to compiling on demand)',
            '  --app=NAME   the application in apps/ (default: app, or the only one)',
        ]);
    }

    public function run(Arguments $arguments, Output $output): int
    {
        $arguments->allowOnly(['check', 'clear', 'app']);
        $project = Project::find($this->root, $arguments->option('app'));
        $file = ManifestFile::path($project->directory);

        try {
            if ($arguments->has('clear')) {
                if (is_file($file) && !unlink($file)) {
                    $output->error('Could not remove ' . $file);

                    return 1;
                }

                $output->line('Manifest removed.');

                return 0;
            }

            if ($arguments->has('check')) {
                $problems = ManifestFile::problems($file, $project->directory);

                if ($problems === []) {
                    $output->line('The Manifest is intact and up to date.');

                    return 0;
                }

                $output->error("The Manifest has problems:\n - " . implode("\n - ", $problems));

                return 1;
            }

            $stats = ManifestCompiler::build($project->directory);
        } catch (ManifestException $exception) {
            $output->error($exception->getMessage());

            foreach (self::HINTS as $needle => $hint) {
                if (str_contains($exception->getMessage(), $needle)) {
                    $output->error('Hint: ' . $hint);
                }
            }

            $output->error('Nothing was changed: the previous Manifest, if any, is intact. Run php ethernize doctor for a wider check.');

            return 1;
        }

        $output->line('Manifest built: ' . $file);
        $output->line(sprintf('  routes: %d (%d static, %d with parameters)', $stats['routes'], $stats['static'], $stats['dynamic']));
        $output->line(sprintf('  compiled services: %d, source files tracked: %d', $stats['services'], $stats['sources']));
        $templates = count(array_filter(glob($project->directory . '/var/cache/weave/*.php') ?: [], static fn (string $file): bool => !str_ends_with($file, '.pulse.php')));
        $output->line(sprintf('  templates compiled: %d (in var/cache/weave)', $templates));
        $output->line(sprintf('  size: %.1f KB, built in %.1f ms', $stats['bytes'] / 1024, $stats['milliseconds']));
        $output->line('Next: php ethernize build --check, then reload PHP/Apache so OPcache renews.');

        return 0;
    }
}
