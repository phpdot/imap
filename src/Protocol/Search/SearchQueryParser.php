<?php
/**
 * Parses tokenized IMAP SEARCH keys into SearchQuery DTO tree.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

declare(strict_types=1);

namespace PHPdot\Mail\IMAP\Protocol\Search;

use PHPdot\Mail\IMAP\DataType\DTO\SearchQuery;
use PHPdot\Mail\IMAP\DataType\DTO\Token;
use PHPdot\Mail\IMAP\DataType\Enum\SearchKey;
use PHPdot\Mail\IMAP\DataType\Enum\TokenType;
use PHPdot\Mail\IMAP\Exception\ParseErrorCode;
use PHPdot\Mail\IMAP\Exception\ParseException;

/**
 * Parses tokenized SEARCH key list into a SearchQuery DTO tree.
 */
final class SearchQueryParser
{
    /**
     * Parses a list of tokens (from the SEARCH command arguments) into SearchQuery objects.
     *
     * @param list<Token> $tokens
     * @return list<SearchQuery>
     */
    public function parse(array $tokens): array
    {
        $pos = 0;
        $len = count($tokens);
        $queries = [];

        while ($pos < $len) {
            $queries[] = $this->parseOne($tokens, $pos, $len);
        }

        return $queries;
    }

    /**
     * @param list<Token> $tokens
     */
    private function parseOne(array $tokens, int &$pos, int $len): SearchQuery
    {
        if ($pos >= $len) {
            throw new ParseException(
                'Unexpected end of search query',
                ParseErrorCode::UnexpectedEndOfInput,
            );
        }

        $token = $tokens[$pos];

        // Parenthesized group: implicit AND
        if ($token->type === TokenType::List_) {
            $pos++;
            $subParser = new self();
            $children = $subParser->parse($token->children);
            if (count($children) === 1) {
                return $children[0];
            }
            return new SearchQuery(SearchKey::All, children: $children);
        }

        // Sequence set used as search criteria
        if ($token->type === TokenType::Sequence || $token->type === TokenType::Number) {
            $pos++;
            return new SearchQuery(SearchKey::Uid, $token->stringValue());
        }

        $keyStr = strtoupper($token->stringValue());
        $searchKey = SearchKey::tryFrom($keyStr);

        if ($searchKey === null) {
            // Might be a sequence set as atom
            if (preg_match('/^[\d*,:]+$/', $token->stringValue()) === 1) {
                $pos++;
                return new SearchQuery(SearchKey::Uid, $token->stringValue());
            }
            throw new ParseException(
                sprintf('Unknown search key: %s', $keyStr),
                ParseErrorCode::InvalidCommand,
                $pos,
            );
        }

        $pos++;
        $argType = SearchKeySchema::getArgType($keyStr);

        // No arguments
        if ($argType === null) {
            return new SearchQuery($searchKey);
        }

        return match ($argType) {
            'string' => $this->parseStringArg($tokens, $pos, $len, $searchKey),
            'date' => $this->parseDateArg($tokens, $pos, $len, $searchKey),
            'number' => $this->parseNumberArg($tokens, $pos, $len, $searchKey),
            'sequence' => $this->parseSequenceArg($tokens, $pos, $len, $searchKey),
            'expression' => $this->parseNot($tokens, $pos, $len),
            'expression,expression' => $this->parseOr($tokens, $pos, $len),
            'string,string' => $this->parseHeaderArg($tokens, $pos, $len),
            default => new SearchQuery($searchKey),
        };
    }

    /**
     * @param list<Token> $tokens
     */
    private function parseStringArg(array $tokens, int &$pos, int $len, SearchKey $key): SearchQuery
    {
        $this->assertHasMore($tokens, $pos, $len, $key->value);
        $value = $tokens[$pos]->stringValue();
        $pos++;
        return new SearchQuery($key, $value);
    }

    /**
     * @param list<Token> $tokens
     */
    private function parseDateArg(array $tokens, int &$pos, int $len, SearchKey $key): SearchQuery
    {
        $this->assertHasMore($tokens, $pos, $len, $key->value);
        $value = $tokens[$pos]->stringValue();
        $pos++;

        $operator = match ($key) {
            SearchKey::Before, SearchKey::SentBefore => '<',
            SearchKey::On, SearchKey::SentOn => '=',
            SearchKey::Since, SearchKey::SentSince => '>=',
            default => '=',
        };

        return new SearchQuery($key, $value, operator: $operator);
    }

    /**
     * @param list<Token> $tokens
     */
    private function parseNumberArg(array $tokens, int &$pos, int $len, SearchKey $key): SearchQuery
    {
        $this->assertHasMore($tokens, $pos, $len, $key->value);
        $value = $tokens[$pos]->intValue();
        $pos++;

        $operator = match ($key) {
            SearchKey::Larger => '>',
            SearchKey::Smaller => '<',
            default => null,
        };

        return new SearchQuery($key, $value, operator: $operator);
    }

    /**
     * @param list<Token> $tokens
     */
    private function parseSequenceArg(array $tokens, int &$pos, int $len, SearchKey $key): SearchQuery
    {
        $this->assertHasMore($tokens, $pos, $len, $key->value);
        $value = $tokens[$pos]->stringValue();
        $pos++;
        return new SearchQuery($key, $value);
    }

    /**
     * @param list<Token> $tokens
     */
    private function parseNot(array $tokens, int &$pos, int $len): SearchQuery
    {
        $child = $this->parseOne($tokens, $pos, $len);
        return SearchQuery::not($child);
    }

    /**
     * @param list<Token> $tokens
     */
    private function parseOr(array $tokens, int &$pos, int $len): SearchQuery
    {
        $left = $this->parseOne($tokens, $pos, $len);
        $right = $this->parseOne($tokens, $pos, $len);
        return SearchQuery::or_($left, $right);
    }

    /**
     * @param list<Token> $tokens
     */
    private function parseHeaderArg(array $tokens, int &$pos, int $len): SearchQuery
    {
        $this->assertHasMore($tokens, $pos, $len, 'HEADER');
        $headerName = $tokens[$pos]->stringValue();
        $pos++;

        $this->assertHasMore($tokens, $pos, $len, 'HEADER');
        $headerValue = $tokens[$pos]->stringValue();
        $pos++;

        return new SearchQuery(SearchKey::Header, $headerValue, header: $headerName);
    }

    /**
     * @param list<Token> $tokens
     */
    private function assertHasMore(array $tokens, int $pos, int $len, string $context): void
    {
        if ($pos >= $len) {
            throw new ParseException(
                sprintf('Missing argument for %s', $context),
                ParseErrorCode::UnexpectedEndOfInput,
                $pos,
            );
        }
    }
}
