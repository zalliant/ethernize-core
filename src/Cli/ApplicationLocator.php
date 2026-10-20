<?php

declare(strict_types=1);

namespace Ethernize\Core\Cli;

/**
 * Finds the applications of a project from its structure: every folder `apps/<name>` that has a
 * `public/index.php` is an application called <name>. Nothing is configured or registered.
 */
final class ApplicationLocator
{
    public function __construct(private readonly string $root)
    {
    }

    /**
     * @return array<string, string> application name => absolute path of its folder
     */
    public function all(): array
    {
        $found = [];
        $apps = $this->root . DIRECTORY_SEPARATOR . 'apps';

        foreach (is_dir($apps) ? (scandir($apps) ?: []) : [] as $name) {
            if (preg_match('/^[a-z][a-z0-9_-]*$/', $name) !== 1) {
                continue;
            }

            $path = $apps . DIRECTORY_SEPARATOR . $name;

            if (is_file($path . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'index.php')) {
                $found[$name] = $path;
            }
        }

        ksort($found);

        return $found;
    }

    public function find(string $name): ?string
    {
        return $this->all()[$name] ?? null;
    }
}
