<?php
declare(strict_types=1);

namespace PHPdot\Mail\IMAP\Server\Event;

use PHPdot\Mail\IMAP\DataType\ValueObject\Mailbox;
use PHPdot\Mail\IMAP\Server\Command\MailboxCommand;

/**
 * Event for CREATE, DELETE, SUBSCRIBE, UNSUBSCRIBE commands.
 */
class MailboxEvent extends Event
{
    public function __construct(
        public readonly MailboxCommand $mailboxCommand,
    ) {
        parent::__construct($mailboxCommand);
    }

    public function mailbox(): Mailbox
    {
        return $this->mailboxCommand->mailbox;
    }
}
