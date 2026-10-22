<?php

declare(strict_types=1);

namespace Ethernize\Core\Cli\Generator;

use Ethernize\Core\Cli\ApplicationLocator;
use Ethernize\Core\Cli\UsageError;

/**
 * Where generated code goes, found from the project itself: the application folder (apps/<name>) and the
 * PSR-4 namespace Composer maps to its src/ folder. A generator never invents a namespace that the autoloader
 * would not find.
 */
final class Project
{
    /**
     * @param string               $root      the project folder (where composer.json is)
     * @param string               $name      application name
     * @param string               $directory absolute path of apps/<name>
     * @param array<string, string> $psr4     namespace prefix (with trailing "\") => directory relative to $root, with "/" and a trailing "/"
     */
    public function __construct(
        public readonly string $root,
        public readonly string $name,
        public readonly string $directory,
        private readonly array $psr4,
    ) {
    }

    /**
     * @throws UsageError when there is no such application or it cannot be told which one is meant
     */
    public static function find(string $root, ?string $app = null): self
    {
        $locator = new ApplicationLocator($root);
        $apps = $locator->all();
        $name = $app ?? (isset($apps['app']) ? 'app' : (count($apps) === 1 ? (string) array_key_first($apps) : null));

        if ($name === null) {
            throw new UsageError($apps === [] ? 'No application found: apps/<name>/public/index.php does not exist.' : 'More than one application: choose with --app=' . implode(' or --app=', array_keys($apps)) . '.');
        }

        $directory = $apps[$name] ?? null;

        if ($directory === null) {
            throw new UsageError(sprintf('There is no application "%s". Available: %s.', $name, $apps === [] ? 'none' : implode(', ', array_keys($apps))));
        }

        return new self($root, $name, $directory, self::readAutoload($root));
    }

    /**
     * The namespace of a folder below the application's src/: namespaceFor(['Models']) is App\Models.
     *
     * @param list<string> $segments
     * @throws UsageError
     */
    public function namespaceFor(array $segments): string
    {
        $src = 'apps/' . $this->name . '/src/';

        foreach ($this->psr4 as $prefix => $path) {
            if ($path === $src) {
                return rtrim($prefix . implode('\\', $segments), '\\');
            }
        }

        throw new UsageError(sprintf('Composer does not map a namespace to apps/%s/src/. Add it to "autoload" > "psr-4" in composer.json (for example "App\\\\": "apps/%s/src/"), then run composer dump-autoload.', $this->name, $this->name));
    }

    /**
     * The file for a class below the application's src/, as a path relative to the project root.
     *
     * @param list<string> $segments folders below src/, then the file name without extension
     */
    public function sourcePath(array $segments, string $extension = '.php'): string
    {
        return 'apps/' . $this->name . '/src/' . implode('/', $segments) . $extension;
    }

    public function appPath(string $relative): string
    {
        return 'apps/' . $this->name . '/' . ltrim($relative, '/');
    }

    /**
     * @return array<string, string>
     */
    private static function readAutoload(string $root): array
    {
        $file = $root . DIRECTORY_SEPARATOR . 'composer.json';
        $json = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        $map = [];

        foreach (['autoload', 'autoload-dev'] as $section) {
            foreach ((array) ($json[$section]['psr-4'] ?? []) as $prefix => $paths) {
                foreach ((array) $paths as $path) {
                    $map[(string) $prefix] = rtrim(str_replace('\\', '/', (string) $path), '/') . '/';
                }
            }
        }

        return $map;
    }
}
