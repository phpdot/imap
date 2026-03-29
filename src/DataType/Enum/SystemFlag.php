<?php
/**
 * IMAP system flags: \Answered, \Flagged, \Deleted, \Seen, \Draft.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

declare(strict_types=1);

namespace PHPdot\Mail\IMAP\DataType\Enum;

enum SystemFlag: string
{
    case Answered = '\\Answered';
    case Flagged = '\\Flagged';
    case Deleted = '\\Deleted';
    case Seen = '\\Seen';
    case Draft = '\\Draft';

    public static function tryFromCaseInsensitive(string $value): ?self
    {
        $lower = strtolower($value);
        foreach (self::cases() as $case) {
            if (strtolower($case->value) === $lower) {
                return $case;
            }
        }
        return null;
    }
}
