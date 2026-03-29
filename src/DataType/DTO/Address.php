<?php
/**
 * IMAP ENVELOPE address: name, adl, mailbox, host.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

declare(strict_types=1);

namespace PHPdot\Mail\IMAP\DataType\DTO;

readonly class Address
{
    public function __construct(
        public ?string $name,
        public ?string $adl,
        public ?string $mailbox,
        public ?string $host,
    ) {}

    public function email(): ?string
    {
        if ($this->mailbox === null || $this->host === null) {
            return null;
        }
        return $this->mailbox . '@' . $this->host;
    }

    public function isGroup(): bool
    {
        return $this->host === null;
    }

    public function isGroupStart(): bool
    {
        return $this->host === null && $this->mailbox !== null;
    }

    public function isGroupEnd(): bool
    {
        return $this->host === null && $this->mailbox === null && $this->name === null;
    }
}
