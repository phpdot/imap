<?php
declare(strict_types=1);

namespace PHPdot\Mail\IMAP\Server\Event;

use PHPdot\Mail\IMAP\DataType\Enum\StatusAttribute;
use PHPdot\Mail\IMAP\DataType\ValueObject\Mailbox;
use PHPdot\Mail\IMAP\Server\Command\StatusCommand;

class StatusEvent extends Event
{
    public function __construct(
        public readonly StatusCommand $statusCommand,
    ) {
        parent::__construct($statusCommand);
    }

    public function mailbox(): Mailbox
    {
        return $this->statusCommand->mailbox;
    }

    /** @return list<StatusAttribute> */
    public function attributes(): array
    {
        return $this->statusCommand->attributes;
    }
}
