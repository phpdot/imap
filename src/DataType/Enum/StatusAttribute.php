<?php
/**
 * STATUS command data items: MESSAGES, UIDNEXT, UIDVALIDITY, UNSEEN, etc.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

declare(strict_types=1);

namespace PHPdot\Mail\IMAP\DataType\Enum;

enum StatusAttribute: string
{
    case Messages = 'MESSAGES';
    case UidNext = 'UIDNEXT';
    case UidValidity = 'UIDVALIDITY';
    case Unseen = 'UNSEEN';
    case Deleted = 'DELETED';
    case Size = 'SIZE';
    case HighestModseq = 'HIGHESTMODSEQ';
    case Recent = 'RECENT';
}
