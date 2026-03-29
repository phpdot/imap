<?php
/**
 * Tests for IMAP sequence set parsing and expansion.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

declare(strict_types=1);

namespace PHPdot\Mail\IMAP\Tests\Unit\DataType\ValueObject;

use PHPdot\Mail\IMAP\DataType\ValueObject\SequenceSet;
use PHPdot\Mail\IMAP\Exception\InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SequenceSetTest extends TestCase
{
    #[Test]
    public function parseSingleNumber(): void
    {
        $set = SequenceSet::fromString('5');
        self::assertCount(1, $set->ranges);
        self::assertSame('5', (string) $set);
    }

    #[Test]
    public function parseRange(): void
    {
        $set = SequenceSet::fromString('1:5');
        self::assertCount(1, $set->ranges);
        self::assertSame('1:5', (string) $set);
    }

    #[Test]
    public function parseMultiple(): void
    {
        $set = SequenceSet::fromString('1,3,5:7');
        self::assertCount(3, $set->ranges);
        self::assertSame('1,3,5:7', (string) $set);
    }

    #[Test]
    public function parseWildcard(): void
    {
        $set = SequenceSet::fromString('1:*');
        self::assertSame('1:*', (string) $set);
    }

    #[Test]
    public function parseStar(): void
    {
        $set = SequenceSet::fromString('*');
        self::assertSame('*', (string) $set);
    }

    #[Test]
    public function parseLastCommand(): void
    {
        $set = SequenceSet::fromString('$');
        self::assertTrue($set->isLastCommand);
        self::assertSame('$', (string) $set);
    }

    #[Test]
    public function invalidThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        SequenceSet::fromString('abc');
    }

    #[Test]
    public function expandSingleNumber(): void
    {
        $set = SequenceSet::fromString('3');
        self::assertSame([3], $set->expand(100));
    }

    #[Test]
    public function expandRange(): void
    {
        $set = SequenceSet::fromString('2:5');
        self::assertSame([2, 3, 4, 5], $set->expand(100));
    }

    #[Test]
    public function expandWildcard(): void
    {
        $set = SequenceSet::fromString('8:*');
        self::assertSame([8, 9, 10], $set->expand(10));
    }

    #[Test]
    public function expandStar(): void
    {
        $set = SequenceSet::fromString('*');
        self::assertSame([15], $set->expand(15));
    }

    #[Test]
    public function expandMultiple(): void
    {
        $set = SequenceSet::fromString('1,3,5:7');
        self::assertSame([1, 3, 5, 6, 7], $set->expand(100));
    }

    #[Test]
    public function expandDeduplicatesAndSorts(): void
    {
        $set = SequenceSet::fromString('5:7,6:8');
        self::assertSame([5, 6, 7, 8], $set->expand(100));
    }

    #[Test]
    public function expandCapsAtMax(): void
    {
        $set = SequenceSet::fromString('1:100');
        $result = $set->expand(5);
        self::assertSame([1, 2, 3, 4, 5], $result);
    }

    #[Test]
    public function fromArrayCompresses(): void
    {
        $set = SequenceSet::fromArray([1, 2, 3, 5, 7, 8, 9]);
        self::assertSame('1:3,5,7:9', (string) $set);
    }

    #[Test]
    public function fromArraySingleElement(): void
    {
        $set = SequenceSet::fromArray([42]);
        self::assertSame('42', (string) $set);
    }

    #[Test]
    public function fromArrayEmpty(): void
    {
        $set = SequenceSet::fromArray([]);
        self::assertTrue($set->isEmpty());
    }

    #[Test]
    public function reverseRangeWorks(): void
    {
        $set = SequenceSet::fromString('5:2');
        self::assertSame([2, 3, 4, 5], $set->expand(100));
    }
}
