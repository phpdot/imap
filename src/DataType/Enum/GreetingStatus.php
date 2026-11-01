<?php

declare(strict_types=1);

/**
 * Server greeting status: OK, PREAUTH, BYE.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Mail\IMAP\DataType\Enum;

enum GreetingStatus: string
{
    case Ok = 'OK';
    case PreAuth = 'PREAUTH';
    case Bye = 'BYE';
}
