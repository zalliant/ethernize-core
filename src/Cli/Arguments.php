<?php

declare(strict_types=1);

namespace Ethernize\Core\Cli;

/**
 * The words after the command name, split into positional values and options.
 *
 * Options are `--name=value` or `--name` (a flag). Everything else is positional. `--` ends option
 * parsing. Short options are not supported on purpose: the launcher has few options and spelled-out
 * names are easier to read in scripts.
 */
final class Arguments
{
    /**
     * @param list<string>          $positional
     * @param array<string, string> $options    flags have the value "1"
     */
    public function __construct(
        public readonly array $positional = [],
        public readonly array $options = [],
    ) {
    }

    /**
     * @param list<string> $words
     */
    public static function parse(array $words): self
    {
        $positional = [];
        $options = [];
        $optionsEnded = false;

        foreach ($words as $word) {
            if ($optionsEnded || !str_starts_with($word, '--')) {
                $positional[] = $word;
                continue;
            }

            if ($word === '--') {
                $optionsEnded = true;
                continue;
            }

            $body = substr($word, 2);
            $position = strpos($body, '=');
            $name = $position === false ? $body : substr($body, 0, $position);

            if (preg_match('/^[a-z][a-z0-9-]*$/', $name) !== 1) {
                throw new UsageError(sprintf('Invalid option "%s".', $word));
            }

            $options[$name] = $position === false ? '1' : substr($body, $position + 1);
        }

        return new self($positional, $options);
    }

    public function has(string $option): bool
    {
        return isset($this->options[$option]);
    }

    public function option(string $option, ?string $default = null): ?string
    {
        return $this->options[$option] ?? $default;
    }

    /**
     * Fails when an option the command does not know was given, so a typo is never silently ignored.
     *
     * @param list<string> $known
     * @throws UsageError
     */
    public function allowOnly(array $known): void
    {
        foreach (array_keys($this->options) as $name) {
            if (!in_array($name, $known, true)) {
                throw new UsageError(sprintf('Unknown option "--%s". Known options: %s.', $name, $known === [] ? 'none' : '--' . implode(', --', $known)));
            }
        }
    }
}
