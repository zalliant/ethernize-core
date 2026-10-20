<?php

declare(strict_types=1);

namespace Ethernize\Core\Manifest;

use Closure;
use Ethernize\Core\Config\Config;

/**
 * Conteúdo já validado de um arquivo de Manifest.
 */
final class Manifest
{
    public const FORMAT = 1;

    /**
     * @param array<string, mixed>                 $meta
     * @param array<string, mixed>                 $config
     * @param array<string, Closure>               $services
     * @param array<string, mixed>                 $extensions
     */
    public function __construct(
        public readonly array $meta,
        private readonly array $config,
        private readonly CompiledRouter $router,
        private readonly array $services,
        private readonly array $extensions = [],
    ) {
    }

    /**
     * Contribuição de um módulo, ou null se ele não contribuiu.
     *
     * @return array<string, mixed>|null
     */
    public function extension(string $key): ?array
    {
        $value = $this->extensions[$key] ?? null;

        return is_array($value) ? $value : null;
    }

    public function config(): Config
    {
        return new Config($this->config);
    }

    public function routes(): CompiledRouter
    {
        return $this->router;
    }

    /**
     * Fábricas de serviços, indexadas pelo nome exato da classe.
     *
     * @return array<string, Closure>
     */
    public function services(): array
    {
        return $this->services;
    }
}
