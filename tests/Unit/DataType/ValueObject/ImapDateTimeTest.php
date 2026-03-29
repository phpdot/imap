<?php
/**
 * Tests for IMAP date/date-time parsing.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

declare(strict_types=1);

namespace PHPdot\Mail\IMAP\Tests\Unit\DataType\ValueObject;

use PHPdot\Mail\IMAP\DataType\ValueObject\ImapDateTime;
use PHPdot\Mail\IMAP\Exception\InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ImapDateTimeTest extends TestCase
{
    #[Test]
    public function parseDateTime(): void
    {
        $dt = ImapDateTime::fromDateTime(' 7-Feb-1994 13:34:02 -0500');
        self::assertSame('1994', $dt->dateTime->format('Y'));
        self::assertSame('02', $dt->dateTime->format('m'));
        self::assertSame('7', $dt->dateTime->format('j'));
    }

    #[Test]
    public function parseDateTimeTwoDigitDay(): void
    {
        $dt = ImapDateTime::fromDateTime('25-Mar-2026 14:30:00 +0000');
        self::assertSame('2026', $dt->dateTime->format('Y'));
        self::assertSame('25', $dt->dateTime->format('d'));
    }

    #[Test]
    public function parseDate(): void
    {
        $dt = ImapDateTime::fromDate('1-Feb-1994');
        self::assertSame('1994', $dt->dateTime->format('Y'));
        self::assertSame('02', $dt->dateTime->format('m'));
    }

    #[Test]
    public function invalidDateTimeThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ImapDateTime::fromDateTime('not-a-date');
    }

    #[Test]
    public function invalidDateThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ImapDateTime::fromDate('not-a-date');
    }

    #[Test]
    public function fromTimestamp(): void
    {
        $dt = ImapDateTime::fromTimestamp(0);
        self::assertSame('1970', $dt->dateTime->format('Y'));
    }

    #[Test]
    public function now(): void
    {
        $dt = ImapDateTime::now();
        self::assertSame(date('Y'), $dt->dateTime->format('Y'));
    }

    #[Test]
    public function toDateTimeString(): void
    {
        $dt = ImapDateTime::fromDateTime('25-Mar-2026 14:30:00 +0000');
        $result = $dt->toDateTimeString();
        self::assertStringContainsString('25-Mar-2026', $result);
        self::assertStringContainsString('14:30:00', $result);
    }

    #[Test]
    public function toDateString(): void
    {
        $dt = ImapDateTime::fromDate('25-Mar-2026');
        self::assertSame('25-Mar-2026', $dt->toDateString());
    }

    #[Test]
    public function stripsQuotes(): void
    {
        $dt = ImapDateTime::fromDateTime('"25-Mar-2026 14:30:00 +0000"');
        self::assertSame('2026', $dt->dateTime->format('Y'));
    }
}
