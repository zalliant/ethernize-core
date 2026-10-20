<?php

declare(strict_types=1);

namespace Ethernize\Core\Events;

use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\EventDispatcher\StoppableEventInterface;

/**
 * Despachante PSR-14 mínimo. Os ouvintes de um evento rodam na ordem de
 * registro e recebem também eventos que herdam da classe ou interface assinada.
 */
final class Dispatcher implements EventDispatcherInterface
{
    /** @var array<class-string, list<callable(object): void>> */
    private array $listeners = [];

    private bool $locked = false;

    /**
     * @param class-string           $event
     * @param callable(object): void $listener
     */
    public function listen(string $event, callable $listener): void
    {
        if ($this->locked) {
            throw new \LogicException('Os ouvintes devem ser registrados durante o boot. Depois disso o despachante é fechado para que ouvintes não se acumulem em processos persistentes.');
        }

        $this->listeners[$event][] = $listener;
    }

    /**
     * Impede novos ouvintes. A Application chama este método ao fim do boot.
     */
    public function lock(): void
    {
        $this->locked = true;
    }

    public function dispatch(object $event): object
    {
        foreach ($this->listeners as $type => $listeners) {
            if (!$event instanceof $type) {
                continue;
            }

            foreach ($listeners as $listener) {
                if ($event instanceof StoppableEventInterface && $event->isPropagationStopped()) {
                    return $event;
                }

                $listener($event);
            }
        }

        return $event;
    }
}
