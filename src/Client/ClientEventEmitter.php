<?php
/**
 * Typed event emitter for IMAP client events.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

declare(strict_types=1);

namespace PHPdot\Mail\IMAP\Client;

use PHPdot\Mail\IMAP\Client\Event\ClientEvent;

/**
 * Typed event emitter for client events.
 */
final class ClientEventEmitter
{
    /** @var array<class-string<ClientEvent>, list<callable>> */
    private array $handlers = [];

    /**
     * @template T of ClientEvent
     * @param class-string<T> $eventClass
     * @param callable(T): void $handler
     */
    public function on(string $eventClass, callable $handler): void
    {
        $this->handlers[$eventClass] ??= [];
        $this->handlers[$eventClass][] = $handler;
    }

    public function emit(ClientEvent $event): void
    {
        $class = $event::class;
        $handlers = $this->handlers[$class] ?? [];

        foreach ($handlers as $handler) {
            $handler($event);
        }

        // Check parent classes
        $parent = get_parent_class($class);
        while ($parent !== false && $parent !== ClientEvent::class) {
            $parentHandlers = $this->handlers[$parent] ?? [];
            foreach ($parentHandlers as $handler) {
                $handler($event);
            }
            $parent = get_parent_class($parent);
        }
    }

    /**
     * @param class-string<ClientEvent> $eventClass
     */
    public function off(string $eventClass): void
    {
        unset($this->handlers[$eventClass]);
    }

    public function removeAll(): void
    {
        $this->handlers = [];
    }
}
