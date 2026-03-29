<?php
declare(strict_types=1);

namespace PHPdot\Mail\IMAP\Server\Event;

use PHPdot\Mail\IMAP\DataType\ValueObject\Mailbox;
use PHPdot\Mail\IMAP\Server\Command\RenameCommand;

class RenameEvent extends Event
{
    public function __construct(
        public readonly RenameCommand $renameCommand,
    ) {
        parent::__construct($renameCommand);
    }

    public function from(): Mailbox
    {
        return $this->renameCommand->from;
    }

    public function to(): Mailbox
    {
        return $this->renameCommand->to;
    }
}
