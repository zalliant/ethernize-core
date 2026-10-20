<?php

declare(strict_types=1);

namespace Ethernize\Core\View;

/**
 * Contrato que o Weave implementará. O Core o usa quando um handler retorna
 * um objeto View.
 */
interface TemplateRendererInterface
{
    /**
     * @param array<string, mixed> $data
     */
    public function render(string $template, array $data = []): string;
}
