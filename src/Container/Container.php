<?php

declare(strict_types=1);

namespace Ethernize\Core\Container;

use Closure;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;

/**
 * Container PSR-11 com autowiring limitado.
 *
 * Uma classe só é instanciada automaticamente quando foi registrada de forma
 * explícita (bind, singleton ou allow) ou quando pertence a um namespace
 * liberado com allow('Meu\\Namespace\\'). Qualquer outra classe, mesmo que
 * exista, resulta em NotFoundException. Interfaces exigem registro.
 */
final class Container implements ContainerInterface
{
    /** @var array<string, Closure(self): mixed> */
    private array $factories = [];

    /** @var array<string, bool> */
    private array $shared = [];

    /** @var array<string, mixed> */
    private array $instances = [];

    /** @var array<string, true> */
    private array $allowedClasses = [];

    /** @var list<string> */
    private array $allowedPrefixes = [];

    /** @var array<string, true> */
    private array $resolving = [];

    /** @var array<string, Closure(self): object> */
    private array $compiled = [];

    /** @var array<string, true> */
    private array $scoped = [];

    /**
     * Registra uma definição que gera uma nova instância a cada resolução.
     */
    public function bind(string $id, Closure|string|null $concrete = null): void
    {
        $this->register($id, $concrete, false);
    }

    /**
     * Registra uma definição resolvida uma única vez.
     */
    public function singleton(string $id, Closure|string|null $concrete = null): void
    {
        $this->register($id, $concrete, true);
    }

    /**
     * Registra uma definição resolvida uma vez por requisição. O Application
     * descarta essas instâncias ao fim de cada requisição, então nada que
     * pertença a um usuário vaza para o próximo em processos persistentes.
     */
    public function scoped(string $id, Closure $factory): void
    {
        unset($this->instances[$id]);
        $this->factories[$id] = $factory;
        $this->shared[$id] = true;
        $this->scoped[$id] = true;
    }

    /**
     * Descarta as instâncias de serviços com escopo de requisição.
     */
    public function resetScoped(): void
    {
        foreach ($this->scoped as $id => $_) {
            unset($this->instances[$id]);
        }
    }

    public function instance(string $id, object $instance): void
    {
        unset($this->factories[$id], $this->shared[$id], $this->scoped[$id]);
        $this->instances[$id] = $instance;
    }

    /**
     * Instala fábricas geradas pelo Manifest. Elas só valem para os ids
     * exatos que contêm e perdem para qualquer registro explícito.
     *
     * @param array<string, Closure(self): object> $factories
     */
    public function useCompiled(array $factories): void
    {
        $this->compiled = $factories;
    }

    /**
     * Indica se há um registro explícito (bind, singleton, scoped ou instance).
     */
    public function isBound(string $id): bool
    {
        return isset($this->instances[$id]) || isset($this->factories[$id]);
    }

    /**
     * Indica se o container instanciaria a classe sozinho, por autowiring.
     */
    public function isAutowirable(string $class): bool
    {
        return $this->canAutowire($class);
    }

    /**
     * Libera o autowiring para uma classe ou, quando termina em barra
     * invertida, para todo um namespace.
     */
    public function allow(string $classOrNamespace): void
    {
        $classOrNamespace = ltrim($classOrNamespace, '\\');

        if (str_ends_with($classOrNamespace, '\\')) {
            $this->allowedPrefixes[] = $classOrNamespace;

            return;
        }

        $this->allowedClasses[$classOrNamespace] = true;
    }

    public function has(string $id): bool
    {
        return isset($this->instances[$id])
            || isset($this->factories[$id])
            || isset($this->compiled[$id])
            || $this->canAutowire($id);
    }

    public function get(string $id): mixed
    {
        if (array_key_exists($id, $this->instances)) {
            return $this->instances[$id];
        }

        $factory = $this->factories[$id] ?? $this->compiled[$id] ?? null;

        if ($factory === null) {
            if (!$this->canAutowire($id)) {
                throw new NotFoundException(sprintf('Serviço "%s" não está registrado nem liberado para autowiring.', $id));
            }

            $factory = fn (self $container): object => $container->autowire($id);
        }

        if (isset($this->resolving[$id])) {
            throw new ContainerException(sprintf('Dependência circular detectada ao resolver "%s".', $id));
        }

        $this->resolving[$id] = true;

        try {
            $value = $factory($this);
        } finally {
            unset($this->resolving[$id]);
        }

        if ($this->shared[$id] ?? false) {
            $this->instances[$id] = $value;
        }

        return $value;
    }

    /**
     * Invoca um callable resolvendo seus parâmetros.
     *
     * Parâmetros são preenchidos, nesta ordem, por nome ($named, com conversão
     * para o tipo escalar declarado), por tipo ($byType), pelo container e,
     * por fim, pelo valor padrão.
     *
     * @param Closure|array{0: object, 1: string} $callable
     * @param array<string, mixed>                $named
     * @param array<class-string, object>         $byType
     */
    public function call(Closure|array $callable, array $named = [], array $byType = []): mixed
    {
        if ($callable instanceof Closure) {
            $arguments = $this->resolveParameters(new ReflectionFunction($callable), $named, $byType);

            return $callable(...$arguments);
        }

        [$object, $method] = $callable;
        $arguments = $this->resolveParameters(new ReflectionMethod($object, $method), $named, $byType);

        return $object->{$method}(...$arguments);
    }

    private function register(string $id, Closure|string|null $concrete, bool $shared): void
    {
        unset($this->instances[$id], $this->scoped[$id]);

        if ($concrete === null || is_string($concrete)) {
            $class = $concrete ?? $id;
            $this->allowedClasses[ltrim($class, '\\')] = true;
            $concrete = static fn (self $container): object => $container->autowire($class);
        }

        $this->factories[$id] = $concrete;
        $this->shared[$id] = $shared;
    }

    private function canAutowire(string $class): bool
    {
        if (!class_exists($class)) {
            return false;
        }

        if (!isset($this->allowedClasses[$class])) {
            $allowed = false;

            foreach ($this->allowedPrefixes as $prefix) {
                if (str_starts_with($class, $prefix)) {
                    $allowed = true;
                    break;
                }
            }

            if (!$allowed) {
                return false;
            }
        }

        return (new ReflectionClass($class))->isInstantiable();
    }

    private function autowire(string $class): object
    {
        if (!class_exists($class)) {
            throw new NotFoundException(sprintf('Classe "%s" não existe.', $class));
        }

        $reflection = new ReflectionClass($class);

        if (!$reflection->isInstantiable()) {
            throw new ContainerException(sprintf('"%s" não pode ser instanciada. Registre uma implementação concreta.', $class));
        }

        $constructor = $reflection->getConstructor();

        if ($constructor === null) {
            return new $class();
        }

        return new $class(...$this->resolveParameters($constructor, [], []));
    }

    /**
     * @param array<string, mixed>        $named
     * @param array<class-string, object> $byType
     * @return list<mixed>
     */
    private function resolveParameters(ReflectionFunctionAbstract $function, array $named, array $byType): array
    {
        $arguments = [];

        foreach ($function->getParameters() as $parameter) {
            if ($parameter->isVariadic()) {
                break;
            }

            $arguments[] = $this->resolveParameter($parameter, $named, $byType);
        }

        return $arguments;
    }

    /**
     * @param array<string, mixed>        $named
     * @param array<class-string, object> $byType
     */
    private function resolveParameter(ReflectionParameter $parameter, array $named, array $byType): mixed
    {
        $name = $parameter->getName();
        $type = $parameter->getType();

        if (array_key_exists($name, $named)) {
            return $this->coerce($named[$name], $type, $name);
        }

        if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
            $typeName = $type->getName();

            foreach ($byType as $object) {
                if ($object instanceof $typeName) {
                    return $object;
                }
            }

            if ($this->has($typeName)) {
                return $this->get($typeName);
            }
        }

        if ($parameter->isDefaultValueAvailable()) {
            return $parameter->getDefaultValue();
        }

        if ($type !== null && $type->allowsNull()) {
            return null;
        }

        throw new ContainerException(sprintf(
            'Não foi possível resolver o parâmetro $%s de %s.',
            $name,
            $this->describe($parameter->getDeclaringFunction()),
        ));
    }

    private function coerce(mixed $value, mixed $type, string $name): mixed
    {
        if (!$type instanceof ReflectionNamedType || !$type->isBuiltin() || !is_string($value)) {
            return $value;
        }

        $converted = match ($type->getName()) {
            'int' => filter_var($value, FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE),
            'float' => is_numeric($value) ? (float) $value : null,
            'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
            default => $value,
        };

        if ($converted === null) {
            throw new ParameterTypeException(sprintf('O valor de $%s não é compatível com o tipo %s.', $name, $type->getName()));
        }

        return $converted;
    }

    private function describe(ReflectionFunctionAbstract $function): string
    {
        if ($function instanceof ReflectionMethod) {
            return $function->getDeclaringClass()->getName() . '::' . $function->getName();
        }

        return $function->getName();
    }
}
