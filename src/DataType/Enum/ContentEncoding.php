<?php

declare(strict_types=1);

/**
 * Content transfer encodings: 7BIT, 8BIT, BASE64, QUOTED-PRINTABLE.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Mail\IMAP\DataType\Enum;

enum ContentEncoding: string
{
    case SevenBit = '7BIT';
    case EightBit = '8BIT';
    case Binary = 'BINARY';
    case Base64 = 'BASE64';
    case QuotedPrintable = 'QUOTED-PRINTABLE';

    public static function tryFromCaseInsensitive(string $value): self|null
    {
        return self::tryFrom(strtoupper($value));
    }
}
