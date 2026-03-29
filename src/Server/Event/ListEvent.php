<?php
declare(strict_types=1);

namespace PHPdot\Mail\IMAP\Server\Event;

use PHPdot\Mail\IMAP\Server\Command\ListCommand;

class ListEvent extends Event
{
    public function __construct(
        public readonly ListCommand $listCommand,
    ) {
        parent::__construct($listCommand);
    }

    public function reference(): string
    {
        return $this->listCommand->reference;
    }

    public function pattern(): string
    {
        return $this->listCommand->pattern;
    }

    public function isLsub(): bool
    {
        return $this->listCommand->isLsub;
    }

    /** @return list<string> */
    public function selectOptions(): array
    {
        return $this->listCommand->selectOptions;
    }

    /** @return list<string> */
    public function returnOptions(): array
    {
        return $this->listCommand->returnOptions;
    }
}
