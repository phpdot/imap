<?php
declare(strict_types=1);

namespace PHPdot\Mail\IMAP\Server\Event;

use PHPdot\Mail\IMAP\DataType\ValueObject\Flag;
use PHPdot\Mail\IMAP\DataType\ValueObject\Mailbox;
use PHPdot\Mail\IMAP\Server\Command\AppendCommand;

class AppendEvent extends Event
{
    public function __construct(
        public readonly AppendCommand $appendCommand,
    ) {
        parent::__construct($appendCommand);
    }

    public function mailbox(): Mailbox
    {
        return $this->appendCommand->mailbox;
    }

    public function message(): string
    {
        return $this->appendCommand->message;
    }

    /** @return list<Flag> */
    public function flags(): array
    {
        return $this->appendCommand->flags;
    }

    public function internalDate(): ?string
    {
        return $this->appendCommand->internalDate;
    }
}
