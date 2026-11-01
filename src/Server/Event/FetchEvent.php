<?php

declare(strict_types=1);

/**
 * Server event for IMAP FETCH command.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Mail\IMAP\Server\Event;

use PHPdot\Mail\IMAP\DataType\DTO\FetchItem;
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

    /**
     * The requested data items with sections and partials attached —
     * prefer this over walking the raw tokens of items().
     *
     * @return list<FetchItem>
     */
    public function fetchItems(): array
    {
        return FetchItem::fromTokens($this->fetchCommand->items);
    }

    public function changedSince(): int|null
    {
        return $this->fetchCommand->changedSince;
    }
}
