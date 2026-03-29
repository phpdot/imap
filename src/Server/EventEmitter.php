<?php
/**
 * Typed event emitter for IMAP server events.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

declare(strict_types=1);

namespace PHPdot\Mail\IMAP\Server;

use PHPdot\Mail\IMAP\Server\Event\Event;

/**
 * Simple typed event emitter for server events.
 */
final class EventEmitter
{
    /** @var array<class-string<Event>, list<callable>> */
    private array $handlers = [];

    /**
     * Register a handler for an event class.
     *
     * @template T of Event
     * @param class-string<T> $eventClass
     * @param callable(T): void $handler
     */
    public function on(string $eventClass, callable $handler): void
    {
        $this->handlers[$eventClass] ??= [];
        $this->handlers[$eventClass][] = $handler;
    }

    /**
     * Emit an event. Calls all registered handlers until one accepts/rejects.
     */
    public function emit(Event $event): void
    {
        $class = $event::class;
        $handlers = $this->handlers[$class] ?? [];

        foreach ($handlers as $handler) {
            $handler($event);
            if ($event->isHandled()) {
                return;
            }
        }

        // Check parent classes
        $parent = get_parent_class($class);
        while ($parent !== false && $parent !== Event::class) {
            $parentHandlers = $this->handlers[$parent] ?? [];
            foreach ($parentHandlers as $handler) {
                $handler($event);
                if ($event->isHandled()) {
                    return;
                }
            }
            $parent = get_parent_class($parent);
        }
    }

    /**
     * Remove all handlers for an event class.
     *
     * @param class-string<Event> $eventClass
     */
    public function off(string $eventClass): void
    {
        unset($this->handlers[$eventClass]);
    }

    /**
     * Remove all handlers.
     */
    public function removeAll(): void
    {
        $this->handlers = [];
    }
}
