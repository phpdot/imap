<?php
/**
 * Server event for IMAP FETCH command.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

declare(strict_types=1);

namespace PHPdot\Mail\IMAP\Server\Event;

use PHPdot\Mail\IMAP\DataType\ValueObject\SequenceSet;
use PHPdot\Mail\IMAP\Server\Command\FetchCommand;

class FetchEvent extends Event
{
    public function __construct(
        public readonly FetchCommand $fetchCommand,
    ) {
        parent::__construct($fetchCommand);
    }

    public function sequenceSet(): SequenceSet
    {
        return $this->fetchCommand->sequenceSet;
    }

    public function isUid(): bool
    {
        return $this->fetchCommand->isUid;
    }

    /** @return list<\PHPdot\Mail\IMAP\DataType\DTO\Token> */
    public function items(): array
    {
        return $this->fetchCommand->items;
    }

    public function changedSince(): ?int
    {
        return $this->fetchCommand->changedSince;
    }
}
