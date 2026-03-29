<?php
/**
 * FETCH macros: ALL, FAST, FULL — expand to predefined attribute sets.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

declare(strict_types=1);

namespace PHPdot\Mail\IMAP\DataType\Enum;

enum FetchMacro: string
{
    case All = 'ALL';
    case Fast = 'FAST';
    case Full = 'FULL';

    /**
     * @return list<FetchAttribute>
     */
    public function expand(): array
    {
        return match ($this) {
            self::All => [
                FetchAttribute::Flags,
                FetchAttribute::InternalDate,
                FetchAttribute::Rfc822Size,
                FetchAttribute::Envelope,
            ],
            self::Fast => [
                FetchAttribute::Flags,
                FetchAttribute::InternalDate,
                FetchAttribute::Rfc822Size,
            ],
            self::Full => [
                FetchAttribute::Flags,
                FetchAttribute::InternalDate,
                FetchAttribute::Rfc822Size,
                FetchAttribute::Envelope,
                FetchAttribute::Body,
            ],
        };
    }
}
