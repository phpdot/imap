<?php
/**
 * Command groups mapping IMAP commands to valid connection states.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

declare(strict_types=1);

namespace PHPdot\Mail\IMAP\DataType\Enum;

enum CommandGroup: string
{
    case Any = 'ANY';
    case NonAuth = 'NON_AUTH';
    case Auth = 'AUTH';
    case Selected = 'SELECTED';

    public function isAllowedIn(ConnectionState $state): bool
    {
        return match ($this) {
            self::Any => true,
            self::NonAuth => $state === ConnectionState::NotAuthenticated,
            self::Auth => $state === ConnectionState::Authenticated || $state === ConnectionState::Selected,
            self::Selected => $state === ConnectionState::Selected,
        };
    }
}
