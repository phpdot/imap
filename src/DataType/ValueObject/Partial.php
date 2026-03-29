<?php
/**
 * Partial fetch range <offset.count> value object.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

declare(strict_types=1);

namespace PHPdot\Mail\IMAP\DataType\ValueObject;

use PHPdot\Mail\IMAP\Exception\InvalidArgumentException;

readonly class Partial implements \Stringable
{
    public function __construct(
        public int $offset,
        public int $count,
    ) {
        if ($offset < 0) {
            throw new InvalidArgumentException('Partial offset must be non-negative');
        }
        if ($count < 1) {
            throw new InvalidArgumentException('Partial count must be positive');
        }
    }

    public static function fromString(string $wire): self
    {
        $wire = trim($wire, '<>');
        $parts = explode('.', $wire, 2);
        if (count($parts) !== 2) {
            throw new InvalidArgumentException(
                sprintf('Invalid partial syntax: expected <offset.count>, got "%s"', $wire),
            );
        }

        return new self(
            offset: (int) $parts[0],
            count: (int) $parts[1],
        );
    }

    public function toWireString(): string
    {
        return sprintf('<%d.%d>', $this->offset, $this->count);
    }

    public function __toString(): string
    {
        return $this->toWireString();
    }
}
