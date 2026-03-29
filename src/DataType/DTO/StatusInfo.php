<?php
/**
 * IMAP STATUS response: message count, UID info, unseen count.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

declare(strict_types=1);

namespace PHPdot\Mail\IMAP\DataType\DTO;

use PHPdot\Mail\IMAP\DataType\ValueObject\Mailbox;

readonly class StatusInfo
{
    public function __construct(
        public Mailbox $mailbox,
        public ?int $messages = null,
        public ?int $uidNext = null,
        public ?int $uidValidity = null,
        public ?int $unseen = null,
        public ?int $deleted = null,
        public ?int $size = null,
        public ?int $highestModseq = null,
        public ?int $recent = null,
    ) {}
}
