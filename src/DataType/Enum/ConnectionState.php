<?php

declare(strict_types=1);

/**
 * IMAP connection states: NotAuthenticated, Authenticated, Selected, Logout.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Mail\IMAP\DataType\Enum;

enum ConnectionState: string
{
    case NotAuthenticated = 'NOT_AUTHENTICATED';
    case Authenticated = 'AUTHENTICATED';
    case Selected = 'SELECTED';
    case Logout = 'LOGOUT';
}
