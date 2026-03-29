<?php
/**
 * Server event for IMAP LOGIN command.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

declare(strict_types=1);

namespace PHPdot\Mail\IMAP\Server\Event;

use PHPdot\Mail\IMAP\Server\Command\LoginCommand;

class LoginEvent extends Event
{
    public function __construct(
        public readonly LoginCommand $loginCommand,
    ) {
        parent::__construct($loginCommand);
    }

    public function username(): string
    {
        return $this->loginCommand->userid;
    }

    public function password(): string
    {
        return $this->loginCommand->password;
    }
}
