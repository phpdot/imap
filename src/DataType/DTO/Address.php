<?php

declare(strict_types=1);

/**
 * IMAP ENVELOPE address: name, adl, mailbox, host.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Mail\IMAP\DataType\DTO;

readonly class Address
{
    public function __construct(
        public string|null $name,
        public string|null $adl,
        public string|null $mailbox,
        public string|null $host,
    ) {}

    public function email(): string|null
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
