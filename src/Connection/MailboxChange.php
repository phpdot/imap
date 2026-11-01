<?php

declare(strict_types=1);

/**
 * Represents a mailbox change for pub/sub notification.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Mail\IMAP\Connection;

readonly class MailboxChange
{
    /**
     * @param list<string>|null $flags
     */
    public function __construct(
        public string $type,
        public int|null $uid = null,
        public int|null $count = null,
        public array|null $flags = null,
        public int|null $modseq = null,
    ) {}

    /**
     * New-mail notification carrying an absolute message count.
     *
     * Only correct when every session sees the same count — prefer
     * added(), which lets each session compute EXISTS from its own view.
     */
    public static function exists(int $count): self
    {
        return new self('exists', count: $count);
    }

    /**
     * A message with this UID was added to the mailbox. Each subscribed
     * session appends it to its own sequence map and reports its own EXISTS.
     */
    public static function added(int $uid): self
    {
        return new self('exists', uid: $uid);
    }

    public static function expunge(int $uid): self
    {
        return new self('expunge', uid: $uid);
    }

    /**
     * @param list<string> $flags
     */
    public static function flagChange(int $uid, array $flags, int|null $modseq = null): self
    {
        return new self('flags', uid: $uid, flags: $flags, modseq: $modseq);
    }
}
