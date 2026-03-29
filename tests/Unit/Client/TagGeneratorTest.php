<?php
/**
 * Tests for sequential IMAP tag generation.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

declare(strict_types=1);

namespace PHPdot\Mail\IMAP\Tests\Unit\Client;

use PHPdot\Mail\IMAP\Client\TagGenerator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TagGeneratorTest extends TestCase
{
    #[Test]
    public function generatesSequentialTags(): void
    {
        $gen = new TagGenerator();
        self::assertSame('A001', (string) $gen->next());
        self::assertSame('A002', (string) $gen->next());
        self::assertSame('A003', (string) $gen->next());
    }

    #[Test]
    public function rollsOverAt999(): void
    {
        $gen = new TagGenerator();
        // Generate A001 through A998
        for ($i = 0; $i < 998; $i++) {
            $gen->next();
        }
        // The 999th call should be A999
        self::assertSame('A999', (string) $gen->next());
        // The 1000th call rolls over to B001
        $tag1000 = $gen->next();
        self::assertSame('B001', (string) $tag1000);
    }

    #[Test]
    public function resetStartsOver(): void
    {
        $gen = new TagGenerator();
        $gen->next();
        $gen->next();
        $gen->reset();
        self::assertSame('A001', (string) $gen->next());
    }

    #[Test]
    public function prefixWrapsAroundFromZ(): void
    {
        $gen = new TagGenerator();
        // Generate through A001-A999, B001-B999, ..., Z001-Z999
        for ($i = 0; $i < 26 * 999; $i++) {
            $gen->next();
        }
        // Next should wrap to A001
        $tag = $gen->next();
        self::assertSame('A001', (string) $tag);
    }
}
