<?php

declare(strict_types=1);

/**
 * Evaluates IMAP SEARCH queries against message data in memory.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Mail\IMAP\Protocol\Search;

use PHPdot\Mail\IMAP\DataType\DTO\SearchQuery;
use PHPdot\Mail\IMAP\DataType\Enum\SearchKey;
use PHPdot\Mail\IMAP\DataType\ValueObject\SequenceSet;
use PHPdot\Mail\IMAP\Exception\InvalidArgumentException;

/**
 * Evaluates search queries against message data in memory.
 *
 * Message data is represented as an associative array with keys:
 * - flags: list<string>
 * - internalDate: DateTimeInterface
 * - headerDate: null|DateTimeInterface
 * - size: int
 * - headers: array<string, string>
 * - bodyText: string
 * - uid: int
 * - sequenceNumber: int
 * - modseq: int (optional, CONDSTORE)
 *
 * @phpstan-type MessageData array{
 *     flags: list<string>,
 *     internalDate: \DateTimeInterface,
 *     headerDate: ?\DateTimeInterface,
 *     size: int,
 *     headers: array<string, string>,
 *     bodyText: string,
 *     uid: int,
 *     sequenceNumber: int,
 *     modseq?: int,
 * }
 */
final class QueryMatcher
{
    /**
     * @param MessageData $message
     * @param int $maxSequence Highest sequence number in the mailbox (resolves `*` in a bare sequence set)
     * @param int $maxUid Highest UID in the mailbox (resolves `*` in `UID <set>`)
     */
    public function matches(SearchQuery $query, array $message, int $maxSequence = 0, int $maxUid = 0): bool
    {
        return match ($query->key) {

            SearchKey::All => $this->matchesAll($query->children, $message, $maxSequence, $maxUid),

            SearchKey::Answered => $this->hasFlag($message, '\\Answered'),
            SearchKey::Deleted => $this->hasFlag($message, '\\Deleted'),
            SearchKey::Draft => $this->hasFlag($message, '\\Draft'),
            SearchKey::Flagged => $this->hasFlag($message, '\\Flagged'),
            SearchKey::Seen => $this->hasFlag($message, '\\Seen'),
            SearchKey::Recent => $this->hasFlag($message, '\\Recent'),
            SearchKey::Unanswered => !$this->hasFlag($message, '\\Answered'),
            SearchKey::Undeleted => !$this->hasFlag($message, '\\Deleted'),
            SearchKey::Undraft => !$this->hasFlag($message, '\\Draft'),
            SearchKey::Unflagged => !$this->hasFlag($message, '\\Flagged'),
            SearchKey::Unseen => !$this->hasFlag($message, '\\Seen'),
            SearchKey::New_ => $this->hasFlag($message, '\\Recent') && !$this->hasFlag($message, '\\Seen'),
            SearchKey::Old => !$this->hasFlag($message, '\\Recent'),
            SearchKey::Keyword => $this->hasFlag($message, (string) $query->value),
            SearchKey::Unkeyword => !$this->hasFlag($message, (string) $query->value),

            SearchKey::Before => $this->compareDate($message['internalDate'], (string) $query->value, '<'),
            SearchKey::On => $this->compareDate($message['internalDate'], (string) $query->value, '='),
            SearchKey::Since => $this->compareDate($message['internalDate'], (string) $query->value, '>='),
            SearchKey::SentBefore => $this->compareHeaderDate($message, (string) $query->value, '<'),
            SearchKey::SentOn => $this->compareHeaderDate($message, (string) $query->value, '='),
            SearchKey::SentSince => $this->compareHeaderDate($message, (string) $query->value, '>='),

            SearchKey::Larger => $message['size'] > (int) $query->value,
            SearchKey::Smaller => $message['size'] < (int) $query->value,

            SearchKey::Bcc => $this->headerContains($message, 'bcc', (string) $query->value),
            SearchKey::Cc => $this->headerContains($message, 'cc', (string) $query->value),
            SearchKey::From => $this->headerContains($message, 'from', (string) $query->value),
            SearchKey::To => $this->headerContains($message, 'to', (string) $query->value),
            SearchKey::Subject => $this->headerContains($message, 'subject', (string) $query->value),
            SearchKey::Body => str_contains(
                mb_strtolower($message['bodyText']),
                mb_strtolower((string) $query->value),
            ),
            SearchKey::Text => $this->textContains($message, (string) $query->value),
            SearchKey::Header => $this->headerContains($message, (string) $query->header, (string) $query->value),

            SearchKey::Uid => $this->inSet(
                (string) $query->value,
                $message['uid'],
                max($maxUid, $message['uid']),
            ),
            SearchKey::SequenceSet => $this->inSet(
                (string) $query->value,
                $message['sequenceNumber'],
                max($maxSequence, $message['sequenceNumber']),
            ),

            SearchKey::Not => $query->children !== []
                && !$this->matches($query->children[0], $message, $maxSequence, $maxUid),
            SearchKey::Or_ => $query->children !== [] && count($query->children) >= 2
                && ($this->matches($query->children[0], $message, $maxSequence, $maxUid)
                    || $this->matches($query->children[1], $message, $maxSequence, $maxUid)),

            SearchKey::Modseq => !isset($message['modseq']) || $message['modseq'] >= (int) $query->value,
        };
    }

    /**
     * @param MessageData $message
     * @param list<SearchQuery> $queries
     */
    public function matchesAll(array $queries, array $message, int $maxSequence = 0, int $maxUid = 0): bool
    {
        foreach ($queries as $query) {
            if (!$this->matches($query, $message, $maxSequence, $maxUid)) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param MessageData $message
     */
    private function hasFlag(array $message, string $flag): bool
    {
        foreach ($message['flags'] as $f) {
            if (strcasecmp($f, $flag) === 0) {
                return true;
            }
        }
        return false;
    }

    private function inSet(string $set, int $number, int $max): bool
    {
        try {
            return SequenceSet::fromString($set)->contains($number, $max);
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    private function compareDate(\DateTimeInterface $date, string $dateStr, string $operator): bool
    {

        $target = \DateTimeImmutable::createFromFormat('!j-M-Y', $dateStr);
        if ($target === false) {
            return false;
        }

        $dateDay = $date->format('Y-m-d');
        $targetDay = $target->format('Y-m-d');

        return match ($operator) {
            '<' => $dateDay < $targetDay,
            '=' => $dateDay === $targetDay,
            '>=' => $dateDay >= $targetDay,
            default => false,
        };
    }

    /**
     * @param MessageData $message
     */
    private function compareHeaderDate(array $message, string $dateStr, string $operator): bool
    {
        $date = $message['headerDate'] ?? $message['internalDate'];
        return $this->compareDate($date, $dateStr, $operator);
    }

    /**
     * @param MessageData $message
     */
    private function headerContains(array $message, string $headerName, string $value): bool
    {
        $headerName = mb_strtolower($headerName);
        foreach ($message['headers'] as $name => $headerValue) {
            if (mb_strtolower($name) === $headerName) {
                if ($value === '') {
                    return true;
                }
                return str_contains(mb_strtolower($headerValue), mb_strtolower($value));
            }
        }
        return false;
    }

    /**
     * @param MessageData $message
     */
    private function textContains(array $message, string $value): bool
    {
        $lower = mb_strtolower($value);

        foreach ($message['headers'] as $headerValue) {
            if (str_contains(mb_strtolower($headerValue), $lower)) {
                return true;
            }
        }

        return str_contains(mb_strtolower($message['bodyText']), $lower);
    }
}
