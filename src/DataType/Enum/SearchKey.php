<?php
/**
 * IMAP SEARCH command keys as defined in RFC 9051.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

declare(strict_types=1);

namespace PHPdot\Mail\IMAP\DataType\Enum;

enum SearchKey: string
{
    case All = 'ALL';
    case Answered = 'ANSWERED';
    case Bcc = 'BCC';
    case Before = 'BEFORE';
    case Body = 'BODY';
    case Cc = 'CC';
    case Deleted = 'DELETED';
    case Draft = 'DRAFT';
    case Flagged = 'FLAGGED';
    case From = 'FROM';
    case Header = 'HEADER';
    case Keyword = 'KEYWORD';
    case Larger = 'LARGER';
    case New_ = 'NEW';
    case Not = 'NOT';
    case Old = 'OLD';
    case On = 'ON';
    case Or_ = 'OR';
    case Recent = 'RECENT';
    case Seen = 'SEEN';
    case SentBefore = 'SENTBEFORE';
    case SentOn = 'SENTON';
    case SentSince = 'SENTSINCE';
    case Since = 'SINCE';
    case Smaller = 'SMALLER';
    case Subject = 'SUBJECT';
    case Text = 'TEXT';
    case To = 'TO';
    case Uid = 'UID';
    case Unanswered = 'UNANSWERED';
    case Undeleted = 'UNDELETED';
    case Undraft = 'UNDRAFT';
    case Unflagged = 'UNFLAGGED';
    case Unkeyword = 'UNKEYWORD';
    case Unseen = 'UNSEEN';
    case Modseq = 'MODSEQ';
}
