<?php

declare(strict_types=1);

namespace Ethernize\Core\Manifest;

use Ethernize\Core\Application;

/**
 * Ponto de extensão do Manifest para módulos (Weave, e no futuro outros).
 *
 * Durante `bin/manifest.php build`, o Core inicializa a aplicação, chama cada
 * contribuidor registrado com Application::addManifestContributor() e grava o
 * retorno na seção "extensions" do Manifest, sob a chave do módulo. O módulo
 * pode também preparar seus próprios artefatos nesse momento (por exemplo,
 * compilar templates). O Core não conhece o conteúdo de nenhuma contribuição.
 *
 * Em execução, a contribuição fica disponível por Application::manifest()?->extension($key).
 */
interface ManifestContributor
{
    /**
     * Chave única do módulo na seção "extensions" (por exemplo "weave").
     */
    public function key(): string;

    /**
     * @throws \Throwable qualquer falha cancela o build e deixa o Manifest anterior intacto
     */
    public function contribute(Application $application): ManifestContribution;
}
