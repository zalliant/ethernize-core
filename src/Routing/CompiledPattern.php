<?php

declare(strict_types=1);

namespace Ethernize\Core\Routing;

/**
 * Resultado da compilação de um caminho de rota.
 *
 * Cada segmento é ['literal' => texto] ou
 * ['param' => nome, 'pattern' => expressão, 'optional' => bool].
 */
final class CompiledPattern
{
    /**
     * @param list<string>                                                                            $parameters
     * @param list<array{literal?: string, param?: string, pattern?: string, optional?: bool}> $segments
     */
    public function __construct(
        public readonly ?string $regex,
        public readonly array $parameters,
        public readonly array $segments,
    ) {
    }

    /**
     * Assinaturas usadas para detectar rotas ambíguas: uma por forma em que o
     * caminho pode casar (com e sem os parâmetros opcionais finais).
     *
     * @return list<string>
     */
    public function signatures(): array
    {
        $signatures = [];

        for ($count = count($this->segments); $count >= 0; $count--) {
            $parts = [];

            foreach (array_slice($this->segments, 0, $count) as $segment) {
                $parts[] = isset($segment['param']) ? '{' . $segment['pattern'] . '}' : $segment['literal'];
            }

            $signatures[] = '/' . implode('/', $parts);

            $next = $this->segments[$count - 1] ?? null;

            if ($next === null || !($next['optional'] ?? false)) {
                break;
            }
        }

        return $signatures;
    }
}
