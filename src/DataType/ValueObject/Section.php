<?php
/**
 * IMAP FETCH BODY section specification: part numbers, HEADER, TEXT, MIME.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

declare(strict_types=1);

namespace PHPdot\Mail\IMAP\DataType\ValueObject;

use PHPdot\Mail\IMAP\DataType\Enum\SectionText;

readonly class Section implements \Stringable
{
    /**
     * @param list<int> $partNumbers
     * @param list<string> $headerList
     */
    public function __construct(
        public ?SectionText $text = null,
        public array $partNumbers = [],
        public array $headerList = [],
    ) {}

    public static function all(): self
    {
        return new self();
    }

    public static function header(): self
    {
        return new self(text: SectionText::Header);
    }

    public static function text(): self
    {
        return new self(text: SectionText::Text);
    }

    /**
     * @param list<string> $fields
     */
    public static function headerFields(array $fields): self
    {
        return new self(
            text: SectionText::HeaderFields,
            headerList: $fields,
        );
    }

    /**
     * @param list<string> $fields
     */
    public static function headerFieldsNot(array $fields): self
    {
        return new self(
            text: SectionText::HeaderFieldsNot,
            headerList: $fields,
        );
    }

    /**
     * @param list<int> $parts
     */
    public static function part(array $parts, ?SectionText $text = null): self
    {
        return new self(
            text: $text,
            partNumbers: $parts,
        );
    }

    public function isEmpty(): bool
    {
        return $this->text === null && $this->partNumbers === [];
    }

    public function toWireString(): string
    {
        $parts = [];

        if ($this->partNumbers !== []) {
            $parts[] = implode('.', array_map('strval', $this->partNumbers));
        }

        if ($this->text !== null) {
            $parts[] = $this->text->value;
        }

        $result = '[' . implode('.', $parts);

        if ($this->headerList !== []) {
            $result .= ' (' . implode(' ', $this->headerList) . ')';
        }

        return $result . ']';
    }

    public function __toString(): string
    {
        return $this->toWireString();
    }
}
