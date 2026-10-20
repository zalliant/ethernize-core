<?php

declare(strict_types=1);

namespace Ethernize\Core\Filesystem;

use RuntimeException;
use Throwable;

/**
 * Grava arquivos PHP gerados de forma atômica: o conteúdo é escrito e validado
 * sintaticamente em um temporário na mesma pasta e só então renomeado. Quem
 * lê nunca encontra um arquivo parcial, e um conteúdo inválido nunca substitui
 * o arquivo anterior.
 */
final class AtomicPhpFile
{
    private function __construct()
    {
    }

    /**
     * @throws RuntimeException
     */
    public static function write(string $file, string $source): void
    {
        $directory = dirname($file);

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Could not create directory: %s', $directory));
        }

        if (function_exists('token_get_all')) {
            try {
                token_get_all($source, TOKEN_PARSE);
            } catch (Throwable $exception) {
                throw new RuntimeException('The generated file is not valid PHP: ' . $exception->getMessage(), 0, $exception);
            }
        }

        $temporary = tempnam($directory, 'gen-');

        if ($temporary === false) {
            throw new RuntimeException('Could not create a temporary file.');
        }

        if (file_put_contents($temporary, $source, LOCK_EX) !== strlen($source)) {
            @unlink($temporary);

            throw new RuntimeException('Could not write the generated file.');
        }

        @chmod($temporary, 0644);

        if (!@rename($temporary, $file)) {
            @unlink($temporary);

            throw new RuntimeException('Could not activate the generated file. If it is in use, try again.');
        }

        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($file, true);
        }
    }
}
