<?php
/**
 * FETCH command data items: ENVELOPE, FLAGS, BODY, BODYSTRUCTURE, UID, etc.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

declare(strict_types=1);

namespace PHPdot\Mail\IMAP\DataType\Enum;

enum FetchAttribute: string
{
    case Envelope = 'ENVELOPE';
    case Flags = 'FLAGS';
    case InternalDate = 'INTERNALDATE';
    case Rfc822Size = 'RFC822.SIZE';
    case Body = 'BODY';
    case BodyStructure = 'BODYSTRUCTURE';
    case Uid = 'UID';
    case Binary = 'BINARY';
    case BinaryPeek = 'BINARY.PEEK';
    case BinarySize = 'BINARY.SIZE';
    case BodySection = 'BODY[';
    case BodyPeek = 'BODY.PEEK[';
    case Modseq = 'MODSEQ';
    case Rfc822 = 'RFC822';
    case Rfc822Header = 'RFC822.HEADER';
    case Rfc822Text = 'RFC822.TEXT';
}
