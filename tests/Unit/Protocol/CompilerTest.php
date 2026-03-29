<?php
/**
 * Tests for the IMAP wire format compiler.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

declare(strict_types=1);

namespace PHPdot\Mail\IMAP\Tests\Unit\Protocol;

use PHPdot\Mail\IMAP\DataType\DTO\Token;
use PHPdot\Mail\IMAP\DataType\Enum\TokenType;
use PHPdot\Mail\IMAP\Protocol\Compiler;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CompilerTest extends TestCase
{
    private Compiler $compiler;

    protected function setUp(): void
    {
        $this->compiler = new Compiler();
    }

    // === compileString (smart quoting) ===

    #[Test]
    public function compileStringAtom(): void
    {
        self::assertSame('INBOX', $this->compiler->compileString('INBOX'));
        // \Seen contains backslash which is a quoted-special, so it gets quoted
        self::assertSame('"\\\\Seen"', $this->compiler->compileString('\\Seen'));
    }

    #[Test]
    public function compileStringQuoted(): void
    {
        self::assertSame('"hello world"', $this->compiler->compileString('hello world'));
    }

    #[Test]
    public function compileStringEmpty(): void
    {
        self::assertSame('""', $this->compiler->compileString(''));
    }

    #[Test]
    public function compileStringNilValue(): void
    {
        // The string "NIL" must be quoted to avoid confusion with the atom NIL
        self::assertSame('"NIL"', $this->compiler->compileString('NIL'));
    }

    #[Test]
    public function compileStringWithCrLfUsesLiteral(): void
    {
        $result = $this->compiler->compileString("hello\r\nworld");
        self::assertStringStartsWith('{', $result);
        self::assertStringContainsString("}\r\n", $result);
    }

    // === compileQuoted ===

    #[Test]
    public function compileQuotedEscapesSpecials(): void
    {
        self::assertSame('"hello"', $this->compiler->compileQuoted('hello'));
        self::assertSame('"hello\\"world"', $this->compiler->compileQuoted('hello"world'));
        self::assertSame('"hello\\\\world"', $this->compiler->compileQuoted('hello\\world'));
    }

    // === compileLiteral ===

    #[Test]
    public function compileLiteralFormat(): void
    {
        self::assertSame("{5}\r\nHello", $this->compiler->compileLiteral('Hello'));
        self::assertSame("{0}\r\n", $this->compiler->compileLiteral(''));
    }

    #[Test]
    public function compileNonSyncLiteral(): void
    {
        self::assertSame("{5+}\r\nHello", $this->compiler->compileNonSyncLiteral('Hello'));
    }

    // === compileNil / compileNumber ===

    #[Test]
    public function compileNil(): void
    {
        self::assertSame('NIL', $this->compiler->compileNil());
    }

    #[Test]
    public function compileNumber(): void
    {
        self::assertSame('42', $this->compiler->compileNumber(42));
        self::assertSame('0', $this->compiler->compileNumber(0));
    }

    // === compileList ===

    #[Test]
    public function compileList(): void
    {
        self::assertSame('(\\Seen \\Flagged)', $this->compiler->compileList(['\\Seen', '\\Flagged']));
        self::assertSame('()', $this->compiler->compileList([]));
    }

    // === compileToken ===

    #[Test]
    public function compileAtomToken(): void
    {
        $token = new Token(TokenType::Atom, 'INBOX');
        self::assertSame('INBOX', $this->compiler->compileToken($token));
    }

    #[Test]
    public function compileNilToken(): void
    {
        $token = new Token(TokenType::Nil);
        self::assertSame('NIL', $this->compiler->compileToken($token));
    }

    #[Test]
    public function compileNumberToken(): void
    {
        $token = new Token(TokenType::Number, 42);
        self::assertSame('42', $this->compiler->compileToken($token));
    }

    #[Test]
    public function compileStringToken(): void
    {
        $token = new Token(TokenType::String_, 'hello world');
        self::assertSame('"hello world"', $this->compiler->compileToken($token));
    }

    #[Test]
    public function compileLiteralToken(): void
    {
        $token = new Token(TokenType::Literal, 'Hello');
        self::assertSame("{5}\r\nHello", $this->compiler->compileToken($token));
    }

    #[Test]
    public function compileSequenceToken(): void
    {
        $token = new Token(TokenType::Sequence, '1:*');
        self::assertSame('1:*', $this->compiler->compileToken($token));
    }

    #[Test]
    public function compileSectionToken(): void
    {
        $token = new Token(TokenType::Section, 'HEADER');
        self::assertSame('[HEADER]', $this->compiler->compileToken($token));
    }

    #[Test]
    public function compilePartialToken(): void
    {
        $token = new Token(TokenType::Partial, '0.1024');
        self::assertSame('<0.1024>', $this->compiler->compileToken($token));
    }

    #[Test]
    public function compileListToken(): void
    {
        $token = new Token(TokenType::List_, null, [
            new Token(TokenType::Atom, '\\Seen'),
            new Token(TokenType::Atom, '\\Flagged'),
        ]);
        self::assertSame('(\\Seen \\Flagged)', $this->compiler->compileToken($token));
    }

    #[Test]
    public function compileNestedListToken(): void
    {
        $token = new Token(TokenType::List_, null, [
            new Token(TokenType::List_, null, [
                new Token(TokenType::Atom, 'a'),
                new Token(TokenType::Atom, 'b'),
            ]),
            new Token(TokenType::Atom, 'c'),
        ]);
        self::assertSame('((a b) c)', $this->compiler->compileToken($token));
    }

    // === Response compilation ===

    #[Test]
    public function compileTaggedResponse(): void
    {
        self::assertSame("A001 OK LOGIN completed\r\n", $this->compiler->compileTaggedResponse('A001', 'OK', 'LOGIN completed'));
    }

    #[Test]
    public function compileUntagged(): void
    {
        self::assertSame("* 172 EXISTS\r\n", $this->compiler->compileUntagged('172 EXISTS'));
    }

    #[Test]
    public function compileContinuation(): void
    {
        self::assertSame("+ Ready\r\n", $this->compiler->compileContinuation('Ready'));
        self::assertSame("+ \r\n", $this->compiler->compileContinuation());
    }

    // === Round-trip tests ===

    #[Test]
    public function roundTripAtom(): void
    {
        $this->assertRoundTrip('INBOX');
    }

    #[Test]
    public function roundTripQuotedString(): void
    {
        $this->assertRoundTrip('"hello world"');
    }

    #[Test]
    public function roundTripNil(): void
    {
        $this->assertRoundTrip('NIL');
    }

    #[Test]
    public function roundTripNumber(): void
    {
        $this->assertRoundTrip('42');
    }

    #[Test]
    public function roundTripList(): void
    {
        $this->assertRoundTrip('(\\Seen \\Flagged)');
    }

    #[Test]
    public function roundTripNestedList(): void
    {
        $this->assertRoundTrip('((a b) c)');
    }

    private function assertRoundTrip(string $input): void
    {
        $tokenizer = new \PHPdot\Mail\IMAP\Protocol\Tokenizer();
        $tokens = $tokenizer->tokenize($input);
        $output = $this->compiler->compileTokenList($tokens);
        self::assertSame($input, $output, sprintf('Round-trip failed: "%s" → "%s"', $input, $output));
    }
}
