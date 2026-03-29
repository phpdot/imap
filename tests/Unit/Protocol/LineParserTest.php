<?php
/**
 * Tests for the IMAP line parser with literal handling.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

declare(strict_types=1);

namespace PHPdot\Mail\IMAP\Tests\Unit\Protocol;

use PHPdot\Mail\IMAP\Protocol\LineParser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LineParserTest extends TestCase
{
    private LineParser $parser;

    protected function setUp(): void
    {
        $this->parser = new LineParser();
    }

    #[Test]
    public function parsesSimpleLine(): void
    {
        $lines = $this->parser->feed("A001 NOOP\r\n");
        self::assertCount(1, $lines);
        self::assertSame('A001 NOOP', $lines[0]);
    }

    #[Test]
    public function parsesMultipleLines(): void
    {
        $lines = $this->parser->feed("A001 NOOP\r\nA002 CAPABILITY\r\n");
        self::assertCount(2, $lines);
        self::assertSame('A001 NOOP', $lines[0]);
        self::assertSame('A002 CAPABILITY', $lines[1]);
    }

    #[Test]
    public function buffersIncompleteLine(): void
    {
        $lines = $this->parser->feed('A001 NO');
        self::assertCount(0, $lines);

        $lines = $this->parser->feed("OP\r\n");
        self::assertCount(1, $lines);
        self::assertSame('A001 NOOP', $lines[0]);
    }

    #[Test]
    public function handlesLiteralSynchronizing(): void
    {
        // First part: command with literal marker
        $lines = $this->parser->feed("A001 APPEND INBOX {11}\r\n");
        self::assertCount(0, $lines); // Still waiting for literal data
        self::assertTrue($this->parser->isInLiteral());
        self::assertTrue($this->parser->needsContinuation());

        // Literal data + rest of command
        $lines = $this->parser->feed("Hello World\r\n");
        self::assertCount(1, $lines);
        self::assertStringContainsString('{11}', $lines[0]);
        self::assertStringContainsString('Hello World', $lines[0]);
    }

    #[Test]
    public function handlesLiteralNonSynchronizing(): void
    {
        $lines = $this->parser->feed("A001 APPEND INBOX {5+}\r\nHello\r\n");
        self::assertCount(1, $lines);
        self::assertFalse($this->parser->needsContinuation());
        self::assertStringContainsString('Hello', $lines[0]);
    }

    #[Test]
    public function handlesZeroLengthLiteral(): void
    {
        $lines = $this->parser->feed("A001 APPEND INBOX {0}\r\n\r\n");
        self::assertCount(1, $lines);
    }

    #[Test]
    public function handlesLiteralInChunks(): void
    {
        // Send literal marker
        $lines = $this->parser->feed("A001 TEST {10}\r\n");
        self::assertCount(0, $lines);
        self::assertSame(10, $this->parser->literalBytesRemaining());

        // Send partial literal data (6 bytes)
        $lines = $this->parser->feed('123456');
        self::assertCount(0, $lines);
        self::assertSame(4, $this->parser->literalBytesRemaining());

        // Send remaining literal data (4 bytes) + CRLF
        $lines = $this->parser->feed("7890\r\n");
        self::assertCount(1, $lines);
        self::assertFalse($this->parser->isInLiteral());
    }

    #[Test]
    public function handlesBareLf(): void
    {
        $lines = $this->parser->feed("A001 NOOP\n");
        self::assertCount(1, $lines);
        self::assertSame('A001 NOOP', $lines[0]);
    }

    #[Test]
    public function resetClearsState(): void
    {
        $this->parser->feed('incomplete');
        $this->parser->reset();

        $lines = $this->parser->feed("A001 OK\r\n");
        self::assertCount(1, $lines);
        self::assertSame('A001 OK', $lines[0]);
    }

    #[Test]
    public function handlesOneByteAtATime(): void
    {
        $command = "A001 FETCH 1 (FLAGS)\r\n";
        $allLines = [];
        for ($i = 0, $len = strlen($command); $i < $len; $i++) {
            $result = $this->parser->feed($command[$i]);
            foreach ($result as $line) {
                $allLines[] = $line;
            }
        }
        self::assertCount(1, $allLines);
        self::assertSame('A001 FETCH 1 (FLAGS)', $allLines[0]);
    }

    #[Test]
    public function handlesMultipleLiteralsInSequence(): void
    {
        // First command with literal
        $lines = $this->parser->feed("A001 CMD {3}\r\nabc\r\n");
        self::assertCount(1, $lines);

        // Second command with literal
        $lines = $this->parser->feed("A002 CMD {2}\r\nxy\r\n");
        self::assertCount(1, $lines);
    }
}
