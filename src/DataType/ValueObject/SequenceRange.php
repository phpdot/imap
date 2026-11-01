<?php

declare(strict_types=1);

/**
 * Single range within an IMAP sequence set (e.g., 1:5 or *).
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Mail\IMAP\DataType\ValueObject;

readonly class SequenceRange implements \Stringable
{
    public function __construct(
        public int|string $start,
        public int|string|null $end = null,
    ) {}

    public function isSingle(): bool
    {
        return $this->end === null;
    }

    public function isWildcard(): bool
    {
        return $this->start === '*' || $this->end === '*';
    }

    /**
     * @return list<int>
     */
    public function expand(int $max): array
    {
        $from = $this->start === '*' ? $max : (int) $this->start;
        $to = $this->end === null ? $from : ($this->end === '*' ? $max : (int) $this->end);

        if ($from === 0 || $to === 0) {
            return [];
        }

        $low = min($from, $to);
        $high = min(max($from, $to), $max);
        $low = max(1, $low);

        $result = [];
        for ($i = $low; $i <= $high; $i++) {
            $result[] = $i;
        }
        return $result;
    }

    /**
     * Whether a number falls in this range, with `*` resolved to $max.
     *
     * Unlike expand(), this never materializes the range — safe for `1:*`
     * against mailboxes with very large UIDs.
     */
    public function contains(int $number, int $max): bool
    {
        if ($number < 1) {
            return false;
        }

        $from = $this->start === '*' ? $max : (int) $this->start;
        $to = $this->end === null ? $from : ($this->end === '*' ? $max : (int) $this->end);

        return $number >= min($from, $to) && $number <= max($from, $to);
    }

    public function __toString(): string
    {
        if ($this->end === null) {
            return (string) $this->start;
        }
        return $this->start . ':' . $this->end;
    }
}
