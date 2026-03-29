<?php
/**
 * FETCH BODY section text specifiers: HEADER, TEXT, MIME.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

declare(strict_types=1);

namespace PHPdot\Mail\IMAP\DataType\Enum;

enum SectionText: string
{
    case Header = 'HEADER';
    case HeaderFields = 'HEADER.FIELDS';
    case HeaderFieldsNot = 'HEADER.FIELDS.NOT';
    case Text = 'TEXT';
    case Mime = 'MIME';
}
