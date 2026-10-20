<?php

declare(strict_types=1);

namespace Ethernize\Core;

use Ethernize\Core\Container\Container;

/**
 * Ponto de extensão para módulos do ecossistema (Gate, Shield, etc.).
 * Os providers listados em app.providers precisam de construtor sem argumentos
 * e são executados na ordem da lista durante o boot.
 */
interface ServiceProvider
{
    /**
     * Registra serviços no container. Não deve depender de serviços de outros providers.
     */
    public function register(Container $container, Application $app): void;
}
