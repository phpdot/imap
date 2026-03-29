<?php
declare(strict_types=1);

namespace PHPdot\Mail\IMAP\Server\Event;

use PHPdot\Mail\IMAP\Server\Command\CompressCommand;

class CompressEvent extends Event
{
    public function __construct(
        public readonly CompressCommand $compressCommand,
    ) {
        parent::__construct($compressCommand);
    }

    public function mechanism(): string
    {
        return $this->compressCommand->mechanism;
    }
}
