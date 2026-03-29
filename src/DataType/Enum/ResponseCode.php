<?php
/**
 * IMAP response codes: ALERT, CAPABILITY, UIDVALIDITY, APPENDUID, etc.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

declare(strict_types=1);

namespace PHPdot\Mail\IMAP\DataType\Enum;

enum ResponseCode: string
{
    case Alert = 'ALERT';
    case BadCharset = 'BADCHARSET';
    case Capability = 'CAPABILITY';
    case Parse = 'PARSE';
    case PermanentFlags = 'PERMANENTFLAGS';
    case ReadOnly = 'READ-ONLY';
    case ReadWrite = 'READ-WRITE';
    case TryCreate = 'TRYCREATE';
    case UidNext = 'UIDNEXT';
    case UidValidity = 'UIDVALIDITY';
    case AppendUid = 'APPENDUID';
    case CopyUid = 'COPYUID';
    case UidNotSticky = 'UIDNOTSTICKY';
    case Unavailable = 'UNAVAILABLE';
    case AuthenticationFailed = 'AUTHENTICATIONFAILED';
    case AuthorizationFailed = 'AUTHORIZATIONFAILED';
    case Expired = 'EXPIRED';
    case PrivacyRequired = 'PRIVACYREQUIRED';
    case ContactAdmin = 'CONTACTADMIN';
    case NoPerm = 'NOPERM';
    case InUse = 'INUSE';
    case ExpungeIssued = 'EXPUNGEISSUED';
    case Corruption = 'CORRUPTION';
    case ServerBug = 'SERVERBUG';
    case ClientBug = 'CLIENTBUG';
    case Cannot = 'CANNOT';
    case Limit = 'LIMIT';
    case OverQuota = 'OVERQUOTA';
    case AlreadyExists = 'ALREADYEXISTS';
    case NonExistent = 'NONEXISTENT';
    case NotSaved = 'NOTSAVED';
    case HasChildren = 'HASCHILDREN';
    case Closed = 'CLOSED';
    case UnknownCte = 'UNKNOWN-CTE';
    case HighestModseq = 'HIGHESTMODSEQ';
    case Modified = 'MODIFIED';
    case Compressed = 'COMPRESSED';
    case TempFail = 'TEMPFAIL';
}
