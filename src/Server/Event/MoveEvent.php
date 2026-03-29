<?php
declare(strict_types=1);

namespace PHPdot\Mail\IMAP\Server\Event;

use PHPdot\Mail\IMAP\DataType\ValueObject\Mailbox;
use PHPdot\Mail\IMAP\DataType\ValueObject\SequenceSet;
use PHPdot\Mail\IMAP\Server\Command\CopyMoveCommand;

class MoveEvent extends Event
{
    public function __construct(
        public readonly CopyMoveCommand $moveCommand,
    ) {
        parent::__construct($moveCommand);
    }

    public function sequenceSet(): SequenceSet
    {
        return $this->moveCommand->sequenceSet;
    }

    public function destination(): Mailbox
    {
        return $this->moveCommand->destination;
    }

    public function isUid(): bool
    {
        return $this->moveCommand->isUid;
    }
}
