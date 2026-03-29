<?php
/**
 * IMAP date/date-time value object with RFC 3501 format parsing.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

declare(strict_types=1);

namespace PHPdot\Mail\IMAP\DataType\ValueObject;

use PHPdot\Mail\IMAP\Exception\InvalidArgumentException;

readonly class ImapDateTime implements \Stringable
{
    private const string DATETIME_PATTERN = '/^(\s?\d{1,2})-(Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)-(\d{4})\s+(\d{2}):(\d{2}):(\d{2})\s+([+-]\d{4})$/i';
    private const string DATE_PATTERN = '/^\d{1,2}-(Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)-\d{4}$/i';

    public \DateTimeImmutable $dateTime;

    public function __construct(\DateTimeImmutable $dateTime)
    {
        $this->dateTime = $dateTime;
    }

    public static function fromDateTime(string $value): self
    {
        $value = trim($value, '"');

        if (preg_match(self::DATETIME_PATTERN, $value) !== 1) {
            throw new InvalidArgumentException(
                sprintf('Invalid IMAP date-time: "%s"', $value),
            );
        }

        $parsed = \DateTimeImmutable::createFromFormat('j-M-Y H:i:s O', trim($value));
        if ($parsed === false) {
            throw new InvalidArgumentException(
                sprintf('Failed to parse IMAP date-time: "%s"', $value),
            );
        }

        return new self($parsed);
    }

    public static function fromDate(string $value): self
    {
        $value = trim($value, '"');

        if (preg_match(self::DATE_PATTERN, $value) !== 1) {
            throw new InvalidArgumentException(
                sprintf('Invalid IMAP date: "%s"', $value),
            );
        }

        $parsed = \DateTimeImmutable::createFromFormat('j-M-Y', trim($value));
        if ($parsed === false) {
            throw new InvalidArgumentException(
                sprintf('Failed to parse IMAP date: "%s"', $value),
            );
        }

        return new self($parsed->setTime(0, 0));
    }

    public static function fromTimestamp(int $timestamp): self
    {
        return new self(
            (new \DateTimeImmutable())->setTimestamp($timestamp),
        );
    }

    public static function now(): self
    {
        return new self(new \DateTimeImmutable());
    }

    public function toDateTimeString(): string
    {
        return $this->dateTime->format(' d-M-Y H:i:s O');
    }

    public function toDateString(): string
    {
        return $this->dateTime->format('j-M-Y');
    }

    public function __toString(): string
    {
        return $this->toDateTimeString();
    }
}
