<?php
declare(strict_types=1);

namespace PHPdot\Mail\IMAP\Server\Event;

use PHPdot\Mail\IMAP\Server\Command\MetadataCommand;

class MetadataEvent extends Event
{
    public function __construct(
        public readonly MetadataCommand $metadataCommand,
    ) {
        parent::__construct($metadataCommand);
    }

    public function mailbox(): string
    {
        return $this->metadataCommand->mailbox;
    }

    /** @return list<string> */
    public function entries(): array
    {
        return $this->metadataCommand->entries;
    }

    /** @return array<string, string|null> */
    public function values(): array
    {
        return $this->metadataCommand->values;
    }
}
