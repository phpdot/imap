<?php
declare(strict_types=1);

namespace PHPdot\Mail\IMAP\Server\Event;

use PHPdot\Mail\IMAP\Server\Command\QuotaCommand;

class QuotaEvent extends Event
{
    public function __construct(
        public readonly QuotaCommand $quotaCommand,
    ) {
        parent::__construct($quotaCommand);
    }

    public function root(): string
    {
        return $this->quotaCommand->root;
    }
}
