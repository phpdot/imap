<?php
/**
 * Tests for IMAP tag value object validation.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

declare(strict_types=1);

namespace PHPdot\Mail\IMAP\Tests\Unit\DataType\ValueObject;

use PHPdot\Mail\IMAP\DataType\ValueObject\Tag;
use PHPdot\Mail\IMAP\Exception\InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TagTest extends TestCase
{
    #[Test]
    public function validTags(): void
    {
        self::assertSame('A001', (string) new Tag('A001'));
        self::assertSame('abc123', (string) new Tag('abc123'));
        self::assertSame('tag.with.dots', (string) new Tag('tag.with.dots'));
    }

    #[Test]
    public function specialTags(): void
    {
        $star = new Tag('*');
        self::assertTrue($star->isUntagged());
        self::assertFalse($star->isContinuation());

        $plus = new Tag('+');
        self::assertTrue($plus->isContinuation());
        self::assertFalse($plus->isUntagged());
    }

    #[Test]
    public function emptyThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Tag('');
    }

    #[Test]
    public function spaceThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Tag('A 001');
    }

    #[Test]
    public function controlCharThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Tag("A\x01B");
    }

    #[Test]
    public function atomSpecialThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Tag('A(B');
    }

    #[Test]
    public function equality(): void
    {
        self::assertTrue((new Tag('A001'))->equals(new Tag('A001')));
        self::assertFalse((new Tag('A001'))->equals(new Tag('A002')));
    }

    #[Test]
    public function stringable(): void
    {
        self::assertSame('TAG', (string) new Tag('TAG'));
    }
}
