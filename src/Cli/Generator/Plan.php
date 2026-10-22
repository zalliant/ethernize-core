<?php

declare(strict_types=1);

namespace Ethernize\Core\Cli\Generator;

use Ethernize\Core\Cli\UsageError;
use RuntimeException;

/**
 * The files a generator wants to create, collected first and written together. Nothing is written until
 * every target has been checked: a name that is already taken stops the whole run, so a half-generated
 * feature never remains. Existing files are replaced only when the caller says so explicitly (--force).
 */
final class Plan
{
    /** @var array<string, string> relative path => contents */
    private array $files = [];

    /** @var list<string> */
    private array $notes = [];

    public function __construct(private readonly string $root)
    {
    }

    public function add(string $relative, string $contents): void
    {
        $relative = str_replace('\\', '/', $relative);

        if (str_contains($relative, '..') || str_starts_with($relative, '/') || preg_match('/^[A-Za-z]:/', $relative) === 1 || str_contains($relative, "\0")) {
            throw new RuntimeException('A generator tried to write outside the project.');
        }

        $this->files[$relative] = $contents;
    }

    /**
     * A line shown after the files, for what the developer still has to do (register a route, run a migration).
     */
    public function note(string $line): void
    {
        $this->notes[] = $line;
    }

    /**
     * @return list<string> the files in the plan
     */
    public function paths(): array
    {
        return array_keys($this->files);
    }

    /**
     * @return list<string> the paths that already exist
     */
    public function conflicts(): array
    {
        return array_values(array_filter(array_keys($this->files), fn (string $relative): bool => file_exists($this->absolute($relative))));
    }

    /**
     * @return list<string>
     */
    public function notes(): array
    {
        return $this->notes;
    }

    public function contents(string $relative): ?string
    {
        return $this->files[$relative] ?? null;
    }

    /**
     * @throws UsageError when a file exists and $force is not set
     */
    public function write(bool $force = false): void
    {
        $conflicts = $this->conflicts();

        if ($conflicts !== [] && !$force) {
            throw new UsageError(sprintf("Nothing was written. These files already exist:\n  %s\nChoose another name, or add --force to replace them.", implode("\n  ", $conflicts)));
        }

        foreach ($this->files as $relative => $contents) {
            $path = $this->absolute($relative);

            if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0775, true) && !is_dir(dirname($path))) {
                throw new RuntimeException(sprintf('Could not create the folder for %s.', $relative));
            }

            $temporary = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';

            if (file_put_contents($temporary, $contents, LOCK_EX) !== strlen($contents) || !@rename($temporary, $path)) {
                @unlink($temporary);

                throw new RuntimeException(sprintf('Could not write %s.', $relative));
            }
        }
    }

    private function absolute(string $relative): string
    {
        return $this->root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    }
}
