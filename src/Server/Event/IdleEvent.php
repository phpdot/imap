<?php
/**
 * Server event for IMAP IDLE with push notification support.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

declare(strict_types=1);

namespace PHPdot\Mail\IMAP\Server\Event;

use PHPdot\Mail\IMAP\Server\Command\Command;

/**
 * Event for IDLE command with push notification support.
 */
class IdleEvent extends Event
{
    /** @var list<string> */
    private array $pendingNotifications = [];
    private bool $done = false;

    public function __construct(Command $command)
    {
        parent::__construct($command);
    }

    /**
     * Queue an untagged response to push to the client during IDLE.
     */
    public function push(string $untaggedResponse): void
    {
        $this->pendingNotifications[] = $untaggedResponse;
    }

    /**
     * @return list<string>
     */
    public function drainNotifications(): array
    {
        $notifications = $this->pendingNotifications;
        $this->pendingNotifications = [];
        return $notifications;
    }

    public function done(): void
    {
        $this->done = true;
    }

    public function isDone(): bool
    {
        return $this->done;
    }
}
