<?php

declare(strict_types=1);

namespace Ethernize\Core\Manifest;

use RuntimeException;

/**
 * Falha ao compilar, gravar, carregar ou verificar o Manifest. As mensagens
 * são para operadores (logs e CLI) e nunca devem chegar ao cliente HTTP.
 */
final class ManifestException extends RuntimeException
{
    /**
     * @param list<string> $problems
     */
    public static function withProblems(string $summary, array $problems): self
    {
        return new self($summary . "\n - " . implode("\n - ", $problems));
    }
}
