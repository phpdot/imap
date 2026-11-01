<?php

declare(strict_types=1);

/**
 * MIME media types for BODYSTRUCTURE: TEXT, APPLICATION, IMAGE, etc.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Mail\IMAP\DataType\Enum;

enum MediaType: string
{
    case Application = 'APPLICATION';
    case Audio = 'AUDIO';
    case Font = 'FONT';
    case Image = 'IMAGE';
    case Message = 'MESSAGE';
    case Model = 'MODEL';
    case Multipart = 'MULTIPART';
    case Text = 'TEXT';
    case Video = 'VIDEO';

    public static function tryFromCaseInsensitive(string $value): self|null
    {
        return self::tryFrom(strtoupper($value));
    }
}
