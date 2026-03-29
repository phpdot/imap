<?php
/**
 * Defines argument types for each IMAP SEARCH key.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

declare(strict_types=1);

namespace PHPdot\Mail\IMAP\Protocol\Search;

/**
 * Defines argument expectations for each IMAP SEARCH key.
 *
 * Argument types:
 * - null: no arguments
 * - 'string': astring argument
 * - 'date': date argument (dd-Mon-yyyy)
 * - 'number': number argument
 * - 'sequence': sequence-set argument
 * - 'expression': recursive search-key
 * - 'string,string': two string arguments (HEADER)
 */
final class SearchKeySchema
{
    /** @var array<string, string|null> */
    private const array SCHEMA = [
        'ALL' => null,
        'ANSWERED' => null,
        'BCC' => 'string',
        'BEFORE' => 'date',
        'BODY' => 'string',
        'CC' => 'string',
        'DELETED' => null,
        'DRAFT' => null,
        'FLAGGED' => null,
        'FROM' => 'string',
        'HEADER' => 'string,string',
        'KEYWORD' => 'string',
        'LARGER' => 'number',
        'MODSEQ' => 'number',
        'NEW' => null,
        'NOT' => 'expression',
        'OLD' => null,
        'ON' => 'date',
        'OR' => 'expression,expression',
        'RECENT' => null,
        'SEEN' => null,
        'SENTBEFORE' => 'date',
        'SENTON' => 'date',
        'SENTSINCE' => 'date',
        'SINCE' => 'date',
        'SMALLER' => 'number',
        'SUBJECT' => 'string',
        'TEXT' => 'string',
        'TO' => 'string',
        'UID' => 'sequence',
        'UNANSWERED' => null,
        'UNDELETED' => null,
        'UNDRAFT' => null,
        'UNFLAGGED' => null,
        'UNKEYWORD' => 'string',
        'UNSEEN' => null,
    ];

    public static function getArgType(string $key): ?string
    {
        return self::SCHEMA[strtoupper($key)] ?? null;
    }

    public static function isKnown(string $key): bool
    {
        return isset(self::SCHEMA[strtoupper($key)]);
    }

    public static function hasArgs(string $key): bool
    {
        $type = self::SCHEMA[strtoupper($key)] ?? null;
        return $type !== null;
    }

    /**
     * Returns the number of arguments expected.
     */
    public static function argCount(string $key): int
    {
        $type = self::SCHEMA[strtoupper($key)] ?? null;
        if ($type === null) {
            return 0;
        }
        return substr_count($type, ',') + 1;
    }
}
