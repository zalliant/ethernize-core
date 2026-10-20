<?php

declare(strict_types=1);

namespace Ethernize\Core\View;

/**
 * Resultado de handler que pede a renderização de um template.
 */
final class View
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        public readonly string $template,
        public readonly array $data = [],
        public readonly int $status = 200,
    ) {
    }
}
