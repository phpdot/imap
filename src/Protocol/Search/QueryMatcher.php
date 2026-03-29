<?php
/**
 * Evaluates IMAP SEARCH queries against message data in memory.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

declare(strict_types=1);

namespace PHPdot\Mail\IMAP\Protocol\Search;

use PHPdot\Mail\IMAP\DataType\DTO\SearchQuery;
use PHPdot\Mail\IMAP\DataType\Enum\SearchKey;

/**
 * Evaluates search queries against message data in memory.
 *
 * Message data is represented as an associative array with keys:
 * - flags: list<string>
 * - internalDate: DateTimeInterface
 * - headerDate: ?DateTimeInterface
 * - size: int
 * - headers: array<string, string>
 * - bodyText: string
 * - uid: int
 * - sequenceNumber: int
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
 * }
 */
final class QueryMatcher
{
    /**
     * @param MessageData $message
     */
    public function matches(SearchQuery $query, array $message): bool
    {
        return match ($query->key) {
            SearchKey::All => true,

            // Flag checks
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

            // Date checks
            SearchKey::Before => $this->compareDate($message['internalDate'], (string) $query->value, '<'),
            SearchKey::On => $this->compareDate($message['internalDate'], (string) $query->value, '='),
            SearchKey::Since => $this->compareDate($message['internalDate'], (string) $query->value, '>='),
            SearchKey::SentBefore => $this->compareHeaderDate($message, (string) $query->value, '<'),
            SearchKey::SentOn => $this->compareHeaderDate($message, (string) $query->value, '='),
            SearchKey::SentSince => $this->compareHeaderDate($message, (string) $query->value, '>='),

            // Size checks
            SearchKey::Larger => $message['size'] > (int) $query->value,
            SearchKey::Smaller => $message['size'] < (int) $query->value,

            // String checks
            SearchKey::Bcc => $this->headerContains($message, 'bcc', (string) $query->value),
            SearchKey::Cc => $this->headerContains($message, 'cc', (string) $query->value),
            SearchKey::From => $this->headerContains($message, 'from', (string) $query->value),
            SearchKey::To => $this->headerContains($message, 'to', (string) $query->value),
            SearchKey::Subject => $this->headerContains($message, 'subject', (string) $query->value),
            SearchKey::Body => str_contains(
                strtolower($message['bodyText']),
                strtolower((string) $query->value),
            ),
            SearchKey::Text => $this->textContains($message, (string) $query->value),
            SearchKey::Header => $this->headerContains($message, (string) $query->header, (string) $query->value),

            // UID
            SearchKey::Uid => true, // UID filtering is done at the query level, not here

            // Compound
            SearchKey::Not => $query->children !== [] && !$this->matches($query->children[0], $message),
            SearchKey::Or_ => $query->children !== [] && count($query->children) >= 2
                && ($this->matches($query->children[0], $message) || $this->matches($query->children[1], $message)),

            // MODSEQ — handled at storage level
            SearchKey::Modseq => true,
        };
    }

    /**
     * @param MessageData $message
     * @param list<SearchQuery> $queries
     */
    public function matchesAll(array $queries, array $message): bool
    {
        foreach ($queries as $query) {
            if (!$this->matches($query, $message)) {
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

    private function compareDate(\DateTimeInterface $date, string $dateStr, string $operator): bool
    {
        $target = \DateTimeImmutable::createFromFormat('j-M-Y', $dateStr);
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
        $headerName = strtolower($headerName);
        foreach ($message['headers'] as $name => $headerValue) {
            if (strtolower($name) === $headerName) {
                if ($value === '') {
                    return true; // header exists
                }
                return str_contains(strtolower($headerValue), strtolower($value));
            }
        }
        return false;
    }

    /**
     * @param MessageData $message
     */
    private function textContains(array $message, string $value): bool
    {
        $lower = strtolower($value);

        // Search headers
        foreach ($message['headers'] as $headerValue) {
            if (str_contains(strtolower($headerValue), $lower)) {
                return true;
            }
        }

        // Search body
        return str_contains(strtolower($message['bodyText']), $lower);
    }
}
