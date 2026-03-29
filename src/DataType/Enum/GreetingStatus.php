<?php
/**
 * Server greeting status: OK, PREAUTH, BYE.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

declare(strict_types=1);

namespace PHPdot\Mail\IMAP\DataType\Enum;

enum GreetingStatus: string
{
    case Ok = 'OK';
    case PreAuth = 'PREAUTH';
    case Bye = 'BYE';
}
