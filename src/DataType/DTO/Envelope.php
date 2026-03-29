<?php
/**
 * IMAP ENVELOPE structure: date, subject, from, to, cc, bcc, message-id.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

declare(strict_types=1);

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
        public ?string $date,
        public ?string $subject,
        public ?array $from,
        public ?array $sender,
        public ?array $replyTo,
        public ?array $to,
        public ?array $cc,
        public ?array $bcc,
        public ?string $inReplyTo,
        public ?string $messageId,
    ) {}
}
