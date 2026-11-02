<?php

declare(strict_types=1);

/**
 * IMAP sequence set: parse, validate, expand, and compress UID/sequence ranges.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Mail\IMAP\DataType\ValueObject;

use PHPdot\Mail\IMAP\Exception\InvalidArgumentException;

readonly class SequenceSet implements \Stringable
{
    private const string VALIDATION_PATTERN = '/^(\d+|\*)(:\d+|:\*)?(,(\d+|\*)(:\d+|:\*)?)*$/';

    /**
     * @param list<SequenceRange> $ranges
     */
    public function __construct(
        public array $ranges,
        public bool $isLastCommand = false,
    ) {}

    public static function fromString(string $wire): self
    {
        $wire = trim($wire);

        if ($wire === '$') {
            return new self([], true);
        }

        if (preg_match(self::VALIDATION_PATTERN, $wire) !== 1) {
            throw new InvalidArgumentException(
                sprintf('Invalid sequence set: "%s"', $wire),
            );
        }

        $ranges = [];
        foreach (explode(',', $wire) as $part) {
            if (str_contains($part, ':')) {
                [$start, $end] = explode(':', $part, 2);
                $ranges[] = new SequenceRange(
                    start: $start === '*' ? '*' : (int) $start,
                    end: $end === '*' ? '*' : (int) $end,
                );
            } else {
                $ranges[] = new SequenceRange(
                    start: $part === '*' ? '*' : (int) $part,
                );
            }
        }

        return new self($ranges);
    }

    /**
     * @param list<int> $numbers
     */
    public static function fromArray(array $numbers): self
    {
        if ($numbers === []) {
            return new self([]);
        }

        sort($numbers);
        $ranges = [];
        $start = $numbers[0];
        $prev = $start;

        for ($i = 1, $len = count($numbers); $i < $len; $i++) {
            if ($numbers[$i] === $prev + 1) {
                $prev = $numbers[$i];
            } else {
                $ranges[] = $start === $prev
                    ? new SequenceRange($start)
                    : new SequenceRange($start, $prev);
                $start = $numbers[$i];
                $prev = $start;
            }
        }

        $ranges[] = $start === $prev
            ? new SequenceRange($start)
            : new SequenceRange($start, $prev);

        return new self($ranges);
    }

    /**
     * @return list<int>
     */
    public function expand(int $max): array
    {
        $bounds = [];
        foreach ($this->ranges as $range) {
            $covered = $range->bounds($max);
            if ($covered !== null) {
                $bounds[] = $covered;
            }
        }
        sort($bounds);

        $result = [];
        $next = 1;
        foreach ($bounds as [$low, $high]) {
            for ($i = max($low, $next); $i <= $high; $i++) {
                $result[] = $i;
            }
            $next = max($next, $high + 1);
        }

        return $result;
    }

    /**
     * Whether a number is a member of this set, with `*` resolved to $max
     * (the highest sequence number or UID in the mailbox).
     */
    public function contains(int $number, int $max): bool
    {
        foreach ($this->ranges as $range) {
            if ($range->contains($number, $max)) {
                return true;
            }
        }

        return false;
    }

    public function isEmpty(): bool
    {
        return $this->ranges === [] && !$this->isLastCommand;
    }

    public function toWireString(): string
    {
        if ($this->isLastCommand) {
            return '$';
        }

        return implode(',', array_map(
            static fn(SequenceRange $r): string => (string) $r,
            $this->ranges,
        ));
    }

    public function __toString(): string
    {
        return $this->toWireString();
    }
}
