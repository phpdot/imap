<?php
/**
 * Tests for IMAP flag value object.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

declare(strict_types=1);

namespace PHPdot\Mail\IMAP\Tests\Unit\DataType\ValueObject;

use PHPdot\Mail\IMAP\DataType\Enum\SystemFlag;
use PHPdot\Mail\IMAP\DataType\ValueObject\Flag;
use PHPdot\Mail\IMAP\Exception\InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FlagTest extends TestCase
{
    #[Test]
    public function systemFlagsRecognized(): void
    {
        $flag = new Flag('\\Seen');
        self::assertTrue($flag->isSystem());
        self::assertFalse($flag->isKeyword());
        self::assertSame(SystemFlag::Seen, $flag->systemFlag);
        self::assertSame('\\Seen', $flag->value);
    }

    #[Test]
    public function systemFlagCaseInsensitive(): void
    {
        $flag = new Flag('\\seen');
        self::assertTrue($flag->isSystem());
        self::assertSame(SystemFlag::Seen, $flag->systemFlag);
        self::assertSame('\\Seen', $flag->value); // normalized
    }

    #[Test]
    public function allSystemFlags(): void
    {
        foreach (['\\Answered', '\\Flagged', '\\Deleted', '\\Seen', '\\Draft'] as $f) {
            $flag = new Flag($f);
            self::assertTrue($flag->isSystem(), "$f should be system flag");
        }
    }

    #[Test]
    public function keywordFlag(): void
    {
        $flag = new Flag('$Forwarded');
        self::assertFalse($flag->isSystem());
        self::assertTrue($flag->isKeyword());
        self::assertNull($flag->systemFlag);
        self::assertSame('$Forwarded', $flag->value);
    }

    #[Test]
    public function customKeyword(): void
    {
        $flag = new Flag('MyLabel');
        self::assertTrue($flag->isKeyword());
    }

    #[Test]
    public function emptyThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Flag('');
    }

    #[Test]
    public function equalsCaseInsensitive(): void
    {
        self::assertTrue((new Flag('\\Seen'))->equals(new Flag('\\seen')));
        self::assertTrue((new Flag('$Junk'))->equals(new Flag('$junk')));
        self::assertFalse((new Flag('\\Seen'))->equals(new Flag('\\Flagged')));
    }

    #[Test]
    public function stringable(): void
    {
        self::assertSame('\\Seen', (string) new Flag('\\Seen'));
        self::assertSame('$Forwarded', (string) new Flag('$Forwarded'));
    }
}
