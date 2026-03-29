<?php
declare(strict_types=1);

namespace PHPdot\Mail\IMAP\Server\Event;

use PHPdot\Mail\IMAP\Server\Command\IdCommand;

class IdEvent extends Event
{
    public function __construct(
        public readonly IdCommand $idCommand,
    ) {
        parent::__construct($idCommand);
    }

    /** @return array<string, string>|null */
    public function params(): ?array
    {
        return $this->idCommand->params;
    }
}
