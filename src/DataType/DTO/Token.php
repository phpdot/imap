<?php
/**
 * Token produced by the IMAP protocol tokenizer.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

declare(strict_types=1);

namespace PHPdot\Mail\IMAP\DataType\DTO;

use PHPdot\Mail\IMAP\DataType\Enum\TokenType;

readonly class Token
{
    /**
     * @param list<Token> $children
     */
    public function __construct(
        public TokenType $type,
        public string|int|null $value = null,
        public array $children = [],
    ) {}

    public function isNil(): bool
    {
        return $this->type === TokenType::Nil;
    }

    public function isAtom(): bool
    {
        return $this->type === TokenType::Atom;
    }

    public function isList(): bool
    {
        return $this->type === TokenType::List_;
    }

    public function isString(): bool
    {
        return $this->type === TokenType::String_;
    }

    public function isLiteral(): bool
    {
        return $this->type === TokenType::Literal;
    }

    public function isLiteral8(): bool
    {
        return $this->type === TokenType::Literal8;
    }

    public function isNumber(): bool
    {
        return $this->type === TokenType::Number;
    }

    public function isSequence(): bool
    {
        return $this->type === TokenType::Sequence;
    }

    public function isSection(): bool
    {
        return $this->type === TokenType::Section;
    }

    /**
     * Returns the string representation of the value.
     */
    public function stringValue(): string
    {
        if ($this->value === null) {
            return '';
        }
        return (string) $this->value;
    }

    /**
     * Returns the integer representation of the value.
     */
    public function intValue(): int
    {
        return (int) $this->value;
    }
}
