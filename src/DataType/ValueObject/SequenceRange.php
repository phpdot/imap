<?php
/**
 * Single range within an IMAP sequence set (e.g., 1:5 or *).
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

declare(strict_types=1);

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

    public function __toString(): string
    {
        if ($this->end === null) {
            return (string) $this->start;
        }
        return $this->start . ':' . $this->end;
    }
}
