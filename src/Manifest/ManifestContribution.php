<?php

declare(strict_types=1);

namespace Ethernize\Core\Manifest;

/**
 * O que um módulo entrega ao Manifest: dados exportáveis (somente textos,
 * números, listas e arrays) e os arquivos de origem que, se mudarem, tornam o
 * Manifest obsoleto.
 */
final class ManifestContribution
{
    /**
     * @param array<string, mixed> $data
     * @param list<string>         $sources caminhos absolutos dentro do diretório base da aplicação
     */
    public function __construct(
        public readonly array $data = [],
        public readonly array $sources = [],
    ) {
    }
}
