<?php
/**
 * Tests for IMAP FETCH BODY section specifications.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

declare(strict_types=1);

namespace PHPdot\Mail\IMAP\Tests\Unit\DataType\ValueObject;

use PHPdot\Mail\IMAP\DataType\Enum\SectionText;
use PHPdot\Mail\IMAP\DataType\ValueObject\Section;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SectionTest extends TestCase
{
    #[Test]
    public function emptySection(): void
    {
        $s = Section::all();
        self::assertTrue($s->isEmpty());
        self::assertSame('[]', $s->toWireString());
    }

    #[Test]
    public function headerSection(): void
    {
        $s = Section::header();
        self::assertSame('[HEADER]', (string) $s);
    }

    #[Test]
    public function textSection(): void
    {
        $s = Section::text();
        self::assertSame('[TEXT]', (string) $s);
    }

    #[Test]
    public function headerFieldsSection(): void
    {
        $s = Section::headerFields(['Subject', 'From', 'Date']);
        self::assertSame('[HEADER.FIELDS (Subject From Date)]', (string) $s);
    }

    #[Test]
    public function headerFieldsNotSection(): void
    {
        $s = Section::headerFieldsNot(['Bcc']);
        self::assertSame('[HEADER.FIELDS.NOT (Bcc)]', (string) $s);
    }

    #[Test]
    public function partNumberSection(): void
    {
        $s = Section::part([1, 2]);
        self::assertSame('[1.2]', (string) $s);
    }

    #[Test]
    public function partWithMime(): void
    {
        $s = Section::part([1, 2], SectionText::Mime);
        self::assertSame('[1.2.MIME]', (string) $s);
    }

    #[Test]
    public function partWithText(): void
    {
        $s = Section::part([1], SectionText::Text);
        self::assertSame('[1.TEXT]', (string) $s);
    }
}
