<?php
declare(strict_types=1);

namespace PHPdot\Mail\IMAP\Server\Event;

use PHPdot\Mail\IMAP\DataType\ValueObject\Mailbox;
use PHPdot\Mail\IMAP\DataType\ValueObject\SequenceSet;
use PHPdot\Mail\IMAP\Server\Command\CopyMoveCommand;

class CopyEvent extends Event
{
    public function __construct(
        public readonly CopyMoveCommand $copyCommand,
    ) {
        parent::__construct($copyCommand);
    }

    public function sequenceSet(): SequenceSet
    {
        return $this->copyCommand->sequenceSet;
    }

    public function destination(): Mailbox
    {
        return $this->copyCommand->destination;
    }

    public function isUid(): bool
    {
        return $this->copyCommand->isUid;
    }
}
