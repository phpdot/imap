<?php

declare(strict_types=1);

/**
 * Tagged response status: OK, NO, BAD.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Mail\IMAP\DataType\Enum;

enum ResponseStatus: string
{
    case Ok = 'OK';
    case No = 'NO';
    case Bad = 'BAD';
}
