<?php
/**
 * Server event for IMAP STORE command.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

declare(strict_types=1);

namespace PHPdot\Mail\IMAP\Server\Event;

use PHPdot\Mail\IMAP\DataType\Enum\StoreOperation;
use PHPdot\Mail\IMAP\DataType\ValueObject\Flag;
use PHPdot\Mail\IMAP\DataType\ValueObject\SequenceSet;
use PHPdot\Mail\IMAP\Server\Command\StoreCommand;

class StoreEvent extends Event
{
    public function __construct(
        public readonly StoreCommand $storeCommand,
    ) {
        parent::__construct($storeCommand);
    }

    public function sequenceSet(): SequenceSet
    {
        return $this->storeCommand->sequenceSet;
    }

    public function operation(): StoreOperation
    {
        return $this->storeCommand->operation;
    }

    /** @return list<Flag> */
    public function flags(): array
    {
        return $this->storeCommand->flags;
    }

    public function isUid(): bool
    {
        return $this->storeCommand->isUid;
    }

    public function isSilent(): bool
    {
        return $this->storeCommand->operation->isSilent();
    }

    public function unchangedSince(): ?int
    {
        return $this->storeCommand->unchangedSince;
    }
}
