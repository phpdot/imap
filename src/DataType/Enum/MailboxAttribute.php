<?php
/**
 * IMAP mailbox attributes for LIST responses including SPECIAL-USE.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

declare(strict_types=1);

namespace PHPdot\Mail\IMAP\DataType\Enum;

enum MailboxAttribute: string
{
    // Selectability flags (RFC 9051 Section 7.3.1)
    case NonExistent = '\\NonExistent';
    case Noselect = '\\Noselect';
    case Marked = '\\Marked';
    case Unmarked = '\\Unmarked';

    // Children flags
    case HasChildren = '\\HasChildren';
    case HasNoChildren = '\\HasNoChildren';

    // Other flags
    case Noinferiors = '\\Noinferiors';
    case Subscribed = '\\Subscribed';
    case Remote = '\\Remote';

    // Special-use (RFC 6154, built into IMAP4rev2)
    case All = '\\All';
    case Archive = '\\Archive';
    case Drafts = '\\Drafts';
    case Flagged = '\\Flagged';
    case Junk = '\\Junk';
    case Sent = '\\Sent';
    case Trash = '\\Trash';

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
