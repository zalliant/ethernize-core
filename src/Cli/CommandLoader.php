<?php

declare(strict_types=1);

namespace Ethernize\Core\Cli;

use Ethernize\Core\Cli\Generator\MakeResourceCommand;
use Ethernize\Core\Cli\Generator\ResourcePart;

/**
 * Finds the commands that installed packages offer. A package lists its classes in its composer.json:
 *
 *   "extra": { "ethernize": {
 *       "commands": ["Vendor\\Package\\Cli\\MakeThingCommand"],
 *       "resource-parts": ["Vendor\\Package\\Cli\\ThingPart"],
 *       "doctor-checks": ["Vendor\\Package\\Cli\\ThingCheck"]
 *   } }
 *
 * A command is a class implementing Command whose constructor takes the project folder (or nothing); a
 * resource part implements ResourcePart and a doctor check implements DoctorCheck; both take nothing. The project's own composer.json is read too, for
 * commands that live in the application. Only classes the autoloader can load are used, and each must
 * implement the right interface; a listed class that is not installed is skipped.
 */
final class CommandLoader
{
    private function __construct()
    {
    }

    /**
     * Registers the commands of the packages, and make:resource with the parts they offer.
     */
    public static function register(Console $console, string $root): void
    {
        $commands = [];
        $parts = [];

        foreach (self::declared($root) as $section) {
            foreach ((array) ($section['commands'] ?? []) as $class) {
                $commands[(string) $class] = true;
            }

            foreach ((array) ($section['resource-parts'] ?? []) as $class) {
                $parts[(string) $class] = true;
            }
        }

        $partObjects = [];
        $checks = [];

        foreach (self::declared($root) as $section) {
            foreach ((array) ($section['doctor-checks'] ?? []) as $class) {
                if (class_exists((string) $class) && is_subclass_of((string) $class, DoctorCheck::class)) {
                    $checks[(string) $class] = new $class();
                }
            }
        }

        foreach (array_keys($parts) as $class) {
            if (class_exists($class) && is_subclass_of($class, ResourcePart::class)) {
                $partObjects[] = new $class();
            }
        }

        foreach (array_keys($commands) as $class) {
            if (!class_exists($class) || !is_subclass_of($class, Command::class)) {
                continue;
            }

            $constructor = (new \ReflectionClass($class))->getConstructor();
            $command = $constructor !== null && $constructor->getNumberOfParameters() > 0 ? new $class($root) : new $class();

            if (!in_array($command->name(), $console->names(), true)) {
                $console->register($command);
            }
        }

        if (!in_array('doctor', $console->names(), true)) {
            $console->register(new DoctorCommand($root, array_values($checks)));
        }

        if (!in_array('make:resource', $console->names(), true)) {
            $console->register(new MakeResourceCommand($root, $partObjects));
        }
    }

    /**
     * @return list<array<string, mixed>> the "extra.ethernize" sections of the project and its packages
     */
    private static function declared(string $root): array
    {
        $sections = [];
        $project = self::json($root . '/composer.json');

        if (is_array($project['extra']['ethernize'] ?? null)) {
            $sections[] = $project['extra']['ethernize'];
        }

        $installed = self::json($root . '/vendor/composer/installed.json');

        foreach ((array) ($installed['packages'] ?? $installed ?? []) as $package) {
            if (is_array($package) && is_array($package['extra']['ethernize'] ?? null)) {
                $sections[] = $package['extra']['ethernize'];
            }
        }

        return $sections;
    }

    /**
     * @return array<string, mixed>
     */
    private static function json(string $file): array
    {
        $contents = is_file($file) ? file_get_contents($file) : false;
        $data = $contents === false ? null : json_decode($contents, true);

        return is_array($data) ? $data : [];
    }
}
