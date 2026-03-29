<?php
/**
 * Pub/sub interface for multi-client mailbox change notifications.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

declare(strict_types=1);

namespace PHPdot\Mail\IMAP\Connection;

/**
 * Implementations: InMemoryNotifier (testing), RedisNotifier (production),
 * SwooleChannelNotifier (Swoole coroutines).
 */
interface NotifierInterface
{
    /**
     * Subscribe a connection to mailbox changes.
     *
     * @param callable(MailboxChange): void $callback
     */
    public function subscribe(string $mailbox, string $connectionId, callable $callback): void;

    /**
     * Unsubscribe a connection from mailbox changes.
     */
    public function unsubscribe(string $mailbox, string $connectionId): void;

    /**
     * Publish a mailbox change. All subscribers except ignoreConnectionId get notified.
     */
    public function publish(string $mailbox, MailboxChange $change, string $ignoreConnectionId = ''): void;
}
