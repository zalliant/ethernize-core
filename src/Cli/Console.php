<?php

declare(strict_types=1);

namespace Ethernize\Core\Cli;

use Ethernize\Core\Http\Diagnosable;
use Throwable;

/**
 * The dispatcher behind `php ethernize`: it finds the command named by the first word and hands it
 * the rest. It knows nothing about what commands do, so `build`, `check`, `clear`, `migrate` or
 * `doctor` are added later by registering one more Command, without touching this class.
 */
final class Console
{
    /** @var array<string, Command> */
    private array $commands = [];

    public function __construct(private readonly string $version = '')
    {
    }

    public function register(Command $command): self
    {
        if (preg_match('/^[a-z][a-z0-9-]*(?::[a-z][a-z0-9-]*)?$/', $command->name()) !== 1) {
            throw new \InvalidArgumentException(sprintf('"%s" is not a valid command name.', $command->name()));
        }

        if (isset($this->commands[$command->name()])) {
            throw new \InvalidArgumentException(sprintf('The command "%s" is already registered.', $command->name()));
        }

        $this->commands[$command->name()] = $command;

        return $this;
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->commands);
    }

    /**
     * @param list<string> $argv the full argument vector, script name first
     */
    public function run(array $argv, Output $output): int
    {
        $words = array_slice($argv, 1);
        $name = $words[0] ?? 'help';

        if (in_array($name, ['help', '--help', '-h', 'list'], true)) {
            return $this->help($words[1] ?? null, $output);
        }

        if (in_array($name, ['--version', '-V'], true)) {
            $output->line('Ethernize ' . ($this->version !== '' ? $this->version : '(unknown version)'));

            return 0;
        }

        $command = $this->commands[$name] ?? null;

        if ($command === null) {
            $output->error(sprintf('Unknown command "%s".%s', $name, $this->suggestion($name)));
            $output->error('Run "php ethernize help" to see the commands.');

            return 2;
        }

        $verbose = false;

        try {
            $rest = array_slice($words, 1);

            if (in_array('--help', $rest, true)) {
                return $this->help($name, $output);
            }

            // --verbose belongs to every command: it only changes how an unexpected failure is reported.
            $verbose = in_array('--verbose', $rest, true);
            $rest = array_values(array_diff($rest, ['--verbose']));

            return $command->run(Arguments::parse($rest), $output);
        } catch (UsageError $error) {
            $output->error($error->getMessage());
            $output->error(sprintf('Run "php ethernize help %s" for usage.', $name));

            return 2;
        } catch (Throwable $error) {
            $output->error(sprintf('The command "%s" failed: %s', $name, $error::class));

            if (!$verbose) {
                $output->error('Run it again with --verbose to see the message and where it came from.');

                return 1;
            }

            for ($cause = $error; $cause !== null; $cause = $cause->getPrevious()) {
                $output->error(sprintf('%s%s: %s (%s:%d)', $cause === $error ? '' : 'Caused by ', $cause::class, $cause->getMessage(), $cause->getFile(), $cause->getLine()));

                if ($cause instanceof Diagnosable && $cause->hint() !== null) {
                    $output->error('Hint: ' . $cause->hint());
                }
            }

            return 1;
        }
    }

    private function help(?string $name, Output $output): int
    {
        if ($name !== null) {
            $command = $this->commands[$name] ?? null;

            if ($command === null) {
                $output->error(sprintf('Unknown command "%s".', $name));

                return 2;
            }

            $output->line($command->usage());

            return 0;
        }

        $output->line('Ethernize' . ($this->version !== '' ? ' ' . $this->version : ''));
        $output->line();
        $output->line('Usage: php ethernize <command> [arguments] [options]');
        $output->line();
        $output->line('Commands:');
        $width = max(array_map('strlen', array_keys($this->commands)) ?: [0]);

        foreach ($this->commands as $command) {
            $output->line(sprintf('  %s  %s', str_pad($command->name(), $width), $command->summary()));
        }

        $output->line();
        $output->line('Run "php ethernize help <command>" for the usage of one command.');

        return 0;
    }

    private function suggestion(string $name): string
    {
        $best = null;
        $distance = 3;

        foreach (array_keys($this->commands) as $candidate) {
            $d = levenshtein($name, $candidate);

            if ($d < $distance) {
                [$best, $distance] = [$candidate, $d];
            }
        }

        return $best === null ? '' : sprintf(' Did you mean "%s"?', $best);
    }
}
