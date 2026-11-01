<?php

declare(strict_types=1);

/**
 * Server event for IMAP SELECT/EXAMINE command.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Mail\IMAP\Server\Event;

use PHPdot\Mail\IMAP\DataType\ValueObject\Mailbox;
use PHPdot\Mail\IMAP\Server\Command\SelectCommand;

class SelectEvent extends Event
{
    public function __construct(
        public readonly SelectCommand $selectCommand,
    ) {
        parent::__construct($selectCommand);
    }

    public function mailbox(): Mailbox
    {
        return $this->selectCommand->mailbox;
    }

    public function isReadOnly(): bool
    {
        return $this->selectCommand->readOnly;
    }

    public function isCondstore(): bool
    {
        return $this->selectCommand->condstore;
    }
}
