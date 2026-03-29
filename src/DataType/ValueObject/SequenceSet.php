<?php
/**
 * IMAP sequence set: parse, validate, expand, and compress UID/sequence ranges.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

declare(strict_types=1);

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
        $result = [];
        foreach ($this->ranges as $range) {
            foreach ($range->expand($max) as $num) {
                $result[] = $num;
            }
        }

        $result = array_values(array_unique($result));
        sort($result);
        return $result;
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
