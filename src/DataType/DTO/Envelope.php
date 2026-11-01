<?php

declare(strict_types=1);

/**
 * IMAP ENVELOPE structure: date, subject, from, to, cc, bcc, message-id.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Mail\IMAP\DataType\DTO;

readonly class Envelope
{
    /**
     * @param list<Address>|null $from
     * @param list<Address>|null $sender
     * @param list<Address>|null $replyTo
     * @param list<Address>|null $to
     * @param list<Address>|null $cc
     * @param list<Address>|null $bcc
     */
    public function __construct(
        public string|null $date,
        public string|null $subject,
        public array|null $from,
        public array|null $sender,
        public array|null $replyTo,
        public array|null $to,
        public array|null $cc,
        public array|null $bcc,
        public string|null $inReplyTo,
        public string|null $messageId,
    ) {}
}
