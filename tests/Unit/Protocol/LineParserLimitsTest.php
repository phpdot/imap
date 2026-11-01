<?php

/**
 * Size limits and literal continuation of the IMAP line parser.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

declare(strict_types=1);

namespace PHPdot\Mail\IMAP\Tests\Unit\Protocol;

use PHPdot\Mail\IMAP\Protocol\LineParser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LineParserLimitsTest extends TestCase
{
    #[Test]
    public function literalDataDoesNotCountAgainstTheLineLimit(): void
    {
        $parser = new LineParser(maxLiteralSize: 1_000_000, maxLineLength: 1024);
        $body = str_repeat("0123456789abcdef\r\n", 10_000); // 180 KB, far over the line limit

        $lines = [];
        foreach (str_split('a1 APPEND INBOX {' . strlen($body) . "+}\r\n" . $body . "\r\n", 8192) as $chunk) {
            $lines = [...$lines, ...$parser->feed($chunk)];
        }

        self::assertCount(1, $lines);
        self::assertStringEndsWith($body, $lines[0]);
        self::assertSame([], $parser->drainRejections());
    }

    #[Test]
    public function overLongCommandLineIsRejectedUnderItsTag(): void
    {
        $parser = new LineParser(maxLineLength: 64);

        $lines = $parser->feed('a7 SEARCH SUBJECT ' . str_repeat('x', 200) . "\r\na8 NOOP\r\n");

        self::assertSame(['a8 NOOP'], $lines);
        self::assertSame([['tag' => 'a7', 'reason' => 'Command line too long']], $parser->drainRejections());
        self::assertSame([], $parser->drainRejections());
    }

    #[Test]
    public function aLineWithoutEndCannotGrowTheBufferForever(): void
    {
        $parser = new LineParser(maxLineLength: 64);

        self::assertSame([], $parser->feed('a1 ' . str_repeat('x', 500)));
        self::assertSame([], $parser->feed(str_repeat('y', 500)));
        self::assertSame(['a2 NOOP'], $parser->feed("tail\r\na2 NOOP\r\n"));
        self::assertSame('a1', $parser->drainRejections()[0]['tag']);
    }

    #[Test]
    public function oversizedSynchronizingLiteralIsRefusedWithoutWaitingForItsData(): void
    {
        $parser = new LineParser(maxLiteralSize: 100);

        self::assertSame([], $parser->feed("a1 APPEND INBOX {5000}\r\n"));
        self::assertFalse($parser->needsContinuation(), 'no go-ahead for a refused literal');
        self::assertFalse($parser->isInLiteral());
        self::assertSame('a1', $parser->drainRejections()[0]['tag']);
        self::assertSame(['a2 NOOP'], $parser->feed("a2 NOOP\r\n"));
    }

    #[Test]
    public function oversizedNonSynchronizingLiteralIsConsumedAndRejected(): void
    {
        $parser = new LineParser(maxLiteralSize: 100);

        $lines = $parser->feed("a1 APPEND INBOX {300+}\r\n" . str_repeat('z', 300) . "\r\na2 NOOP\r\n");

        self::assertSame(['a2 NOOP'], $lines);
        self::assertTrue($parser->wasLiteralRejected());
        self::assertSame('Literal exceeds the maximum size', $parser->drainRejections()[0]['reason']);
    }

    #[Test]
    public function continuationIsRequestedOncePerLiteral(): void
    {
        $parser = new LineParser();

        $parser->feed("a1 APPEND INBOX {10}\r\n");
        self::assertTrue($parser->needsContinuation());

        $parser->feed('12345');
        self::assertFalse($parser->needsContinuation(), 'not again for the next chunk of the same literal');

        self::assertSame(["a1 APPEND INBOX {10}\r\n1234567890"], $parser->feed("67890\r\n"));
        self::assertFalse($parser->needsContinuation());
    }
}
