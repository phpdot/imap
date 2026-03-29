<?php
declare(strict_types=1);

namespace PHPdot\Mail\IMAP\Server\Event;

use PHPdot\Mail\IMAP\Server\Command\AclCommand;

class AclEvent extends Event
{
    public function __construct(
        public readonly AclCommand $aclCommand,
    ) {
        parent::__construct($aclCommand);
    }

    public function mailbox(): string
    {
        return $this->aclCommand->mailbox;
    }

    public function identifier(): ?string
    {
        return $this->aclCommand->identifier;
    }

    public function rights(): ?string
    {
        return $this->aclCommand->rights;
    }
}
