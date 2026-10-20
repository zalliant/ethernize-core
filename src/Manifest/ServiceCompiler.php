<?php

declare(strict_types=1);

namespace Ethernize\Core\Manifest;

use Ethernize\Core\Container\Container;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;

/**
 * Transforma o autowiring de uma classe em uma expressão PHP equivalente.
 *
 * Só gera código para o que é estaticamente determinável:
 * - dependências que o container instanciaria sozinho viram "new" aninhado;
 * - dependências com registro explícito (bind, singleton, instance, interface
 *   registrada) viram "$c->get(...)", preservando o comportamento dinâmico;
 * - parâmetros opcionais usam o valor padrão, se for escalar, array ou null.
 *
 * Qualquer outra situação (escalar obrigatório, dependência indisponível,
 * ciclo, valor padrão que não pode ser escrito como código) faz a classe ser
 * ignorada, e o container a resolve em tempo de execução como antes.
 *
 * Todos os nomes de classe vêm de reflexão sobre classes existentes, nunca de
 * entrada externa, e valores literais passam por var_export.
 */
final class ServiceCompiler
{
    private const MAX_DEPTH = 8;

    /** @var array<string, string> */
    private array $skipped = [];

    public function __construct(private readonly Container $container)
    {
    }

    /**
     * @param list<class-string> $classes
     * @return array<string, string> classe => expressão PHP
     */
    public function compile(array $classes): array
    {
        $compiled = [];

        foreach (array_unique($classes) as $class) {
            if ($this->container->isBound($class)) {
                $this->skipped[$class] = 'possui registro explícito no container';
                continue;
            }

            $expression = $this->expression($class, [], 0);

            if ($expression !== null) {
                $compiled[$class] = $expression;
            }
        }

        return $compiled;
    }

    /**
     * Classes ignoradas e o motivo.
     *
     * @return array<string, string>
     */
    public function skipped(): array
    {
        return $this->skipped;
    }

    /**
     * @param list<string> $stack
     */
    private function expression(string $class, array $stack, int $depth): ?string
    {
        if (!class_exists($class) || !$this->container->isAutowirable($class)) {
            return $this->skip($stack[0] ?? $class, sprintf('%s não é instanciável por autowiring', $class));
        }

        if (in_array($class, $stack, true) || $depth > self::MAX_DEPTH) {
            return $this->skip($stack[0] ?? $class, 'dependência circular ou profunda demais');
        }

        $constructor = (new ReflectionClass($class))->getConstructor();
        $arguments = [];

        foreach ($constructor?->getParameters() ?? [] as $parameter) {
            if ($parameter->isVariadic()) {
                break;
            }

            $argument = $this->argument($parameter, [...$stack, $class], $depth + 1);

            if ($argument === null) {
                return null;
            }

            $arguments[] = $argument;
        }

        return 'new \\' . ltrim($class, '\\') . '(' . implode(', ', $arguments) . ')';
    }

    /**
     * @param list<string> $stack
     */
    private function argument(ReflectionParameter $parameter, array $stack, int $depth): ?string
    {
        $type = $parameter->getType();

        if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
            $name = $type->getName();

            if ($this->container->isBound($name)) {
                return '$c->get(' . var_export($name, true) . ')';
            }

            if (class_exists($name) && $this->container->isAutowirable($name)) {
                return $this->expression($name, $stack, $depth);
            }
        }

        if ($parameter->isDefaultValueAvailable()) {
            $literal = $this->literal($parameter->getDefaultValue());

            if ($literal !== null) {
                return $literal;
            }

            return $this->skip($stack[0], sprintf('valor padrão de $%s não pode ser escrito como código', $parameter->getName()));
        }

        if ($type !== null && $type->allowsNull()) {
            return 'null';
        }

        return $this->skip($stack[0], sprintf('o parâmetro $%s não pode ser resolvido estaticamente', $parameter->getName()));
    }

    private function literal(mixed $value): ?string
    {
        if ($value === null || is_scalar($value)) {
            return var_export($value, true);
        }

        if (is_array($value)) {
            foreach ($value as $item) {
                if ($this->literal($item) === null) {
                    return null;
                }
            }

            return var_export($value, true);
        }

        return null;
    }

    private function skip(string $class, string $reason): null
    {
        $this->skipped[$class] ??= $reason;

        return null;
    }
}
