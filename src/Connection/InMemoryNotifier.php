<?php

declare(strict_types=1);

/**
 * In-memory MailboxChange pub/sub — same-process connections only.
 *
 * The single-process StreamServer uses this directly. Multi-process
 * deployments swap in a Redis pub/sub implementation behind the same
 * interface: workers publish, every process pushes to its local IDLE
 * connections.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Mail\IMAP\Connection;

final class InMemoryNotifier implements NotifierInterface
{
    /** @var array<string, callable(MailboxChange): void> callbacks keyed "<MAILBOX>\0<connectionId>" */
    private array $subscribers = [];

    public function subscribe(string $mailbox, string $connectionId, callable $callback): void
    {
        $this->subscribers[strtoupper($mailbox) . "\0" . $connectionId] = $callback;
    }

    public function unsubscribe(string $mailbox, string $connectionId): void
    {
        unset($this->subscribers[strtoupper($mailbox) . "\0" . $connectionId]);
    }

    public function publish(string $mailbox, MailboxChange $change, string $ignoreConnectionId = ''): void
    {
        $prefix = strtoupper($mailbox) . "\0";
        foreach ($this->subscribers as $key => $callback) {
            if (!str_starts_with($key, $prefix)) {
                continue;
            }
            $connectionId = substr($key, strlen($prefix));
            if ($connectionId === $ignoreConnectionId) {
                continue;
            }
            $callback($change);
        }
    }
}
