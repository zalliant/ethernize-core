<?php

declare(strict_types=1);

namespace Ethernize\Core;

/**
 * Provider que precisa agir depois que todos os registros terminaram, por
 * exemplo assinando eventos ou adicionando rotas que usam serviços de outros módulos.
 */
interface BootableProvider extends ServiceProvider
{
    public function boot(Application $app): void;
}
