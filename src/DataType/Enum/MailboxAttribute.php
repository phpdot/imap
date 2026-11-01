<?php

declare(strict_types=1);

/**
 * IMAP mailbox attributes for LIST responses including SPECIAL-USE.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Mail\IMAP\DataType\Enum;

enum MailboxAttribute: string
{
    case NonExistent = '\\NonExistent';
    case Noselect = '\\Noselect';
    case Marked = '\\Marked';
    case Unmarked = '\\Unmarked';

    case HasChildren = '\\HasChildren';
    case HasNoChildren = '\\HasNoChildren';

    case Noinferiors = '\\Noinferiors';
    case Subscribed = '\\Subscribed';
    case Remote = '\\Remote';

    case All = '\\All';
    case Archive = '\\Archive';
    case Drafts = '\\Drafts';
    case Flagged = '\\Flagged';
    case Junk = '\\Junk';
    case Sent = '\\Sent';
    case Trash = '\\Trash';

    public static function tryFromCaseInsensitive(string $value): self|null
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
