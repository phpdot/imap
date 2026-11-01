<?php

declare(strict_types=1);

/**
 * IMAP STATUS response: message count, UID info, unseen count.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Mail\IMAP\DataType\DTO;

use PHPdot\Mail\IMAP\DataType\ValueObject\Mailbox;

readonly class StatusInfo
{
    public function __construct(
        public Mailbox $mailbox,
        public int|null $messages = null,
        public int|null $uidNext = null,
        public int|null $uidValidity = null,
        public int|null $unseen = null,
        public int|null $deleted = null,
        public int|null $size = null,
        public int|null $highestModseq = null,
        public int|null $recent = null,
    ) {}
}
