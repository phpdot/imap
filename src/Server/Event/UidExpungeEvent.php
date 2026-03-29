<?php
declare(strict_types=1);

namespace PHPdot\Mail\IMAP\Server\Event;

use PHPdot\Mail\IMAP\DataType\ValueObject\SequenceSet;
use PHPdot\Mail\IMAP\Server\Command\UidExpungeCommand;

class UidExpungeEvent extends Event
{
    public function __construct(
        public readonly UidExpungeCommand $uidExpungeCommand,
    ) {
        parent::__construct($uidExpungeCommand);
    }

    public function sequenceSet(): SequenceSet
    {
        return $this->uidExpungeCommand->sequenceSet;
    }
}
