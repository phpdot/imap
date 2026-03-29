<?php
/**
 * State-machine tokenizer for IMAP wire format.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

declare(strict_types=1);

namespace PHPdot\Mail\IMAP\Protocol;

use PHPdot\Mail\IMAP\DataType\DTO\Token;
use PHPdot\Mail\IMAP\DataType\Enum\TokenType;
use PHPdot\Mail\IMAP\Exception\ParseErrorCode;
use PHPdot\Mail\IMAP\Exception\ParseException;

/**
 * State-machine tokenizer for IMAP wire format.
 *
 * Parses IMAP command/response strings into a list of Token DTOs.
 * Based on RFC 9051 Section 9 formal syntax.
 */
final class Tokenizer
{
    private const int MAX_DEPTH = 25;

    /**
     * Tokenizes an IMAP wire-format string into a list of Token DTOs.
     *
     * @return list<Token>
     */
    public function tokenize(string $input): array
    {
        $pos = 0;
        $len = strlen($input);
        return $this->parseTokens($input, $pos, $len, 0);
    }

    /**
     * @return list<Token>
     */
    private function parseTokens(string $str, int &$pos, int $len, int $depth): array
    {
        $tokens = [];

        while ($pos < $len) {
            $ord = ord($str[$pos]);

            // Skip whitespace
            if ($ord === 0x20) {
                $pos++;
                continue;
            }

            // Skip CRLF
            if ($ord === 0x0D || $ord === 0x0A) {
                $pos++;
                continue;
            }

            // End of list — return to parent
            if ($str[$pos] === ')') {
                if ($depth === 0) {
                    throw new ParseException(
                        'Unexpected ")" without matching "("',
                        ParseErrorCode::UnexpectedToken,
                        $pos,
                        $str,
                    );
                }
                $pos++;
                return $tokens;
            }

            $tokens[] = $this->parseOneToken($str, $pos, $len, $depth);
        }

        // If we're inside a list (depth > 0) and ran out of input, the list is unterminated
        if ($depth > 0) {
            throw new ParseException(
                'Unterminated list: missing ")"',
                ParseErrorCode::UnterminatedList,
                $pos,
                $str,
            );
        }

        return $tokens;
    }

    private function parseOneToken(string $str, int &$pos, int $len, int $depth): Token
    {
        $chr = $str[$pos];

        return match (true) {
            $chr === '"' => $this->parseQuotedString($str, $pos, $len),
            $chr === '(' => $this->parseList($str, $pos, $len, $depth),
            $chr === '[' => $this->parseSection($str, $pos, $len),
            $chr === '{' => $this->parseLiteral($str, $pos, $len, false),
            $chr === '~' && $pos + 1 < $len && $str[$pos + 1] === '{' => $this->parseLiteral($str, $pos, $len, true),
            $chr === '<' => $this->parsePartial($str, $pos, $len),
            default => $this->parseAtom($str, $pos, $len),
        };
    }

    private function parseQuotedString(string $str, int &$pos, int $len): Token
    {
        $pos++; // skip opening "
        $value = '';

        while ($pos < $len) {
            $c = $str[$pos];
            if ($c === '\\' && $pos + 1 < $len) {
                $value .= $str[$pos + 1];
                $pos += 2;
            } elseif ($c === '"') {
                $pos++; // skip closing "
                return new Token(TokenType::String_, $value);
            } else {
                $value .= $c;
                $pos++;
            }
        }

        throw new ParseException(
            'Unterminated quoted string',
            ParseErrorCode::UnterminatedString,
            $pos,
            $str,
        );
    }

    private function parseList(string $str, int &$pos, int $len, int $depth): Token
    {
        $pos++; // skip (
        $newDepth = $depth + 1;
        if ($newDepth > self::MAX_DEPTH) {
            throw new ParseException(
                'Maximum nesting depth exceeded',
                ParseErrorCode::MaxDepthExceeded,
                $pos,
                $str,
            );
        }

        $children = $this->parseTokens($str, $pos, $len, $newDepth);

        return new Token(TokenType::List_, null, $children);
    }

    private function parseSection(string $str, int &$pos, int $len): Token
    {
        $start = $pos;
        $pos++; // skip [
        $content = '';
        $bracketDepth = 1;

        while ($pos < $len) {
            if ($str[$pos] === '[') {
                $bracketDepth++;
            } elseif ($str[$pos] === ']') {
                $bracketDepth--;
                if ($bracketDepth === 0) {
                    break;
                }
            }
            $content .= $str[$pos];
            $pos++;
        }

        if ($bracketDepth > 0) {
            throw new ParseException(
                'Unterminated section',
                ParseErrorCode::UnterminatedSection,
                $start,
                $str,
            );
        }

        $pos++; // skip ]
        return new Token(TokenType::Section, $content);
    }

    private function parseLiteral(string $str, int &$pos, int $len, bool $isLiteral8): Token
    {
        $start = $pos;
        if ($isLiteral8) {
            $pos++; // skip ~
        }
        $pos++; // skip {
        $sizeStr = '';

        while ($pos < $len && $str[$pos] !== '}') {
            if ($str[$pos] !== '+') {
                $sizeStr .= $str[$pos];
            }
            $pos++;
        }

        if ($pos >= $len) {
            throw new ParseException(
                'Unterminated literal size',
                ParseErrorCode::UnterminatedLiteral,
                $start,
                $str,
            );
        }

        $pos++; // skip }

        // Skip CRLF after }
        if ($pos < $len && $str[$pos] === "\r") {
            $pos++;
        }
        if ($pos < $len && $str[$pos] === "\n") {
            $pos++;
        }

        $litSize = (int) $sizeStr;
        $value = '';
        if ($litSize > 0 && $pos < $len) {
            $available = min($litSize, $len - $pos);
            $value = substr($str, $pos, $available);
            $pos += $available;
        }

        $type = $isLiteral8 ? TokenType::Literal8 : TokenType::Literal;
        return new Token($type, $value);
    }

    private function parsePartial(string $str, int &$pos, int $len): Token
    {
        $start = $pos;
        $pos++; // skip <
        $content = '';

        while ($pos < $len && $str[$pos] !== '>') {
            $content .= $str[$pos];
            $pos++;
        }

        if ($pos >= $len) {
            throw new ParseException(
                'Unterminated partial',
                ParseErrorCode::InvalidPartial,
                $start,
                $str,
            );
        }

        $pos++; // skip >
        return new Token(TokenType::Partial, $content);
    }

    private function parseAtom(string $str, int &$pos, int $len): Token
    {
        $value = '';

        while ($pos < $len) {
            $c = $str[$pos];
            $o = ord($c);

            // Terminators
            if ($o === 0x20 || $c === '(' || $c === ')' || $c === '['
                || $c === ']' || $c === '{' || $c === '<' || $c === '"'
                || $o === 0x0D || $o === 0x0A) {
                break;
            }

            $value .= $c;
            $pos++;
        }

        if ($value === '') {
            $badOrd = $pos < $len ? ord($str[$pos]) : 0;
            throw new ParseException(
                sprintf('Unexpected character: 0x%02X', $badOrd),
                ParseErrorCode::InvalidCharacter,
                $pos,
                $str,
            );
        }

        return $this->classifyAndBuild($value);
    }

    private function classifyAndBuild(string $value): Token
    {
        // NIL
        if (strtoupper($value) === 'NIL') {
            return new Token(TokenType::Nil);
        }

        // Pure number
        if (preg_match('/^\d+$/', $value) === 1) {
            return new Token(TokenType::Number, (int) $value);
        }

        // Single * (wildcard in sequence)
        if ($value === '*') {
            return new Token(TokenType::Sequence, $value);
        }

        // Sequence set: contains : or , with valid chars
        if ((str_contains($value, ':') || str_contains($value, ','))
            && preg_match('/^(\d+|\*)([:,](\d+|\*)|:\*)*$/', $value) === 1) {
            return new Token(TokenType::Sequence, $value);
        }

        return new Token(TokenType::Atom, $value);
    }
}
