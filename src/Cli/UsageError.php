<?php

declare(strict_types=1);

namespace Ethernize\Core\Cli;

use RuntimeException;

/**
 * The command line was used wrongly. The message tells the person what to type instead; the
 * console prints it and exits with code 2.
 */
final class UsageError extends RuntimeException
{
}
