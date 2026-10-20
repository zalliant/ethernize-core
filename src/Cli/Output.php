<?php

declare(strict_types=1);

namespace Ethernize\Core\Cli;

/**
 * Where a command writes. Standard output and standard error by default; tests pass memory streams.
 */
final class Output
{
    /**
     * @param resource $out
     * @param resource $err
     */
    public function __construct(private $out = STDOUT, private $err = STDERR)
    {
    }

    public function line(string $text = ''): void
    {
        fwrite($this->out, $text . PHP_EOL);
    }

    public function error(string $text): void
    {
        fwrite($this->err, $text . PHP_EOL);
    }
}
