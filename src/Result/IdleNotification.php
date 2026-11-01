<?php

declare(strict_types=1);

/**
 * Notification received during IMAP IDLE mode.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Mail\IMAP\Result;

use PHPdot\Mail\IMAP\DataType\ValueObject\Flag;

readonly class IdleNotification
{
    /**
     * @param list<Flag>|null $flags
     */
    public function __construct(
        public string $type,
        public int|null $number = null,
        public array|null $flags = null,
    ) {}

    public function isNewMessage(): bool
    {
        return $this->type === 'exists';
    }

    public function isExpunge(): bool
    {
        return $this->type === 'expunge';
    }

    public function isFlagChange(): bool
    {
        return $this->type === 'fetch' && $this->flags !== null;
    }
}
