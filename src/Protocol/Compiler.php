<?php
/**
 * Compiles IMAP tokens and typed values to wire format.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

declare(strict_types=1);

namespace PHPdot\Mail\IMAP\Protocol;

use PHPdot\Mail\IMAP\DataType\DTO\Token;
use PHPdot\Mail\IMAP\DataType\Enum\TokenType;
use PHPdot\Mail\IMAP\Exception\CompileException;
use PHPdot\Mail\IMAP\Protocol\Charset\CharacterSet;

/**
 * Compiles Token trees and typed values back into IMAP wire format.
 */
final class Compiler
{
    /**
     * Compiles a Token tree back to wire format.
     */
    public function compileToken(Token $token): string
    {
        return match ($token->type) {
            TokenType::Nil => 'NIL',
            TokenType::Atom => $token->stringValue(),
            TokenType::Number => (string) $token->intValue(),
            TokenType::String_ => $this->compileQuoted($token->stringValue()),
            TokenType::Literal => $this->compileLiteral($token->stringValue()),
            TokenType::Literal8 => $this->compileLiteral8($token->stringValue()),
            TokenType::Sequence => $token->stringValue(),
            TokenType::Section => '[' . $token->stringValue() . ']',
            TokenType::Partial => '<' . $token->stringValue() . '>',
            TokenType::List_ => '(' . $this->compileTokenList($token->children) . ')',
        };
    }

    /**
     * @param list<Token> $tokens
     */
    public function compileTokenList(array $tokens): string
    {
        $parts = [];
        foreach ($tokens as $token) {
            $parts[] = $this->compileToken($token);
        }
        return implode(' ', $parts);
    }

    /**
     * Compiles a string value using smart quoting:
     * - If valid atom, emit unquoted
     * - If contains only printable ASCII, emit quoted with escaping
     * - Otherwise emit as literal
     */
    public function compileString(string $value): string
    {
        if ($value === '') {
            return '""';
        }

        if (strtoupper($value) === 'NIL') {
            return $this->compileQuoted($value);
        }

        if (CharacterSet::isValidAtom($value)) {
            return $value;
        }

        if ($this->needsLiteral($value)) {
            return $this->compileLiteral($value);
        }

        return $this->compileQuoted($value);
    }

    public function compileQuoted(string $value): string
    {
        $escaped = str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
        return '"' . $escaped . '"';
    }

    public function compileLiteral(string $value): string
    {
        return '{' . strlen($value) . "}\r\n" . $value;
    }

    public function compileLiteral8(string $value): string
    {
        return '~{' . strlen($value) . "}\r\n" . $value;
    }

    public function compileNonSyncLiteral(string $value): string
    {
        return '{' . strlen($value) . "+}\r\n" . $value;
    }

    public function compileNil(): string
    {
        return 'NIL';
    }

    public function compileNumber(int $value): string
    {
        return (string) $value;
    }

    /**
     * @param list<string> $items
     */
    public function compileList(array $items): string
    {
        return '(' . implode(' ', $items) . ')';
    }

    /**
     * @param list<string|null> $items
     */
    public function compileParenthesized(array $items): string
    {
        $compiled = [];
        foreach ($items as $item) {
            $compiled[] = $item ?? 'NIL';
        }
        return '(' . implode(' ', $compiled) . ')';
    }

    /**
     * Compiles a tagged response line.
     */
    public function compileTaggedResponse(string $tag, string $status, string $text = ''): string
    {
        $line = $tag . ' ' . $status;
        if ($text !== '') {
            $line .= ' ' . $text;
        }
        return $line . "\r\n";
    }

    /**
     * Compiles an untagged response line.
     */
    public function compileUntagged(string $content): string
    {
        return '* ' . $content . "\r\n";
    }

    /**
     * Compiles a continuation response.
     */
    public function compileContinuation(string $text = ''): string
    {
        if ($text === '') {
            return "+ \r\n";
        }
        return '+ ' . $text . "\r\n";
    }

    private function needsLiteral(string $value): bool
    {
        for ($i = 0, $len = strlen($value); $i < $len; $i++) {
            $ord = ord($value[$i]);
            if ($ord === 0x0D || $ord === 0x0A || $ord === 0x00) {
                return true;
            }
            if ($ord > 0x7F) {
                return true;
            }
        }
        return false;
    }
}
