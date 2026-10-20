<?php

declare(strict_types=1);

namespace Ethernize\Core\Events;

use Psr\Http\Message\ServerRequestInterface;
use Throwable;

/** Disparado quando uma exceção é convertida em resposta (inclui 404 e 405). */
final class ExceptionOccurred
{
    public function __construct(
        public readonly ServerRequestInterface $request,
        public readonly Throwable $exception,
    ) {
    }
}
