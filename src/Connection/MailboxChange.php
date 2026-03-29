<?php
/**
 * Represents a mailbox change for pub/sub notification.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

declare(strict_types=1);

namespace PHPdot\Mail\IMAP\Connection;

readonly class MailboxChange
{
    /**
     * @param list<string>|null $flags
     */
    public function __construct(
        public string $type,
        public ?int $uid = null,
        public ?int $count = null,
        public ?array $flags = null,
        public ?int $modseq = null,
    ) {}

    public static function exists(int $count): self
    {
        return new self('exists', count: $count);
    }

    public static function expunge(int $uid): self
    {
        return new self('expunge', uid: $uid);
    }

    /**
     * @param list<string> $flags
     */
    public static function flagChange(int $uid, array $flags, ?int $modseq = null): self
    {
        return new self('flags', uid: $uid, flags: $flags, modseq: $modseq);
    }
}
