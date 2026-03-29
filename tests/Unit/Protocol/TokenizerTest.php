<?php
/**
 * Tests for the IMAP protocol tokenizer.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

declare(strict_types=1);

namespace PHPdot\Mail\IMAP\Tests\Unit\Protocol;

use PHPdot\Mail\IMAP\DataType\Enum\TokenType;
use PHPdot\Mail\IMAP\Exception\ParseException;
use PHPdot\Mail\IMAP\Protocol\Tokenizer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TokenizerTest extends TestCase
{
    private Tokenizer $tokenizer;

    protected function setUp(): void
    {
        $this->tokenizer = new Tokenizer();
    }

    // === ATOMS ===

    #[Test]
    public function parsesSimpleAtom(): void
    {
        $tokens = $this->tokenizer->tokenize('CAPABILITY');
        self::assertCount(1, $tokens);
        self::assertSame(TokenType::Atom, $tokens[0]->type);
        self::assertSame('CAPABILITY', $tokens[0]->value);
    }

    #[Test]
    public function parsesMultipleAtoms(): void
    {
        $tokens = $this->tokenizer->tokenize('A001 FETCH');
        self::assertCount(2, $tokens);
        self::assertSame('A001', $tokens[0]->stringValue());
        self::assertSame('FETCH', $tokens[1]->stringValue());
    }

    #[Test]
    public function parsesAtomWithBackslash(): void
    {
        $tokens = $this->tokenizer->tokenize('\\Seen');
        self::assertCount(1, $tokens);
        self::assertSame(TokenType::Atom, $tokens[0]->type);
        self::assertSame('\\Seen', $tokens[0]->value);
    }

    // === NIL ===

    #[Test]
    public function parsesNil(): void
    {
        $tokens = $this->tokenizer->tokenize('NIL');
        self::assertCount(1, $tokens);
        self::assertSame(TokenType::Nil, $tokens[0]->type);
        self::assertNull($tokens[0]->value);
        self::assertTrue($tokens[0]->isNil());
    }

    #[Test]
    public function parsesNilCaseInsensitive(): void
    {
        $tokens = $this->tokenizer->tokenize('nil');
        self::assertSame(TokenType::Nil, $tokens[0]->type);

        $tokens = $this->tokenizer->tokenize('Nil');
        self::assertSame(TokenType::Nil, $tokens[0]->type);
    }

    // === NUMBERS ===

    #[Test]
    public function parsesNumber(): void
    {
        $tokens = $this->tokenizer->tokenize('172');
        self::assertCount(1, $tokens);
        self::assertSame(TokenType::Number, $tokens[0]->type);
        self::assertSame(172, $tokens[0]->value);
    }

    #[Test]
    public function parsesZero(): void
    {
        $tokens = $this->tokenizer->tokenize('0');
        self::assertSame(TokenType::Number, $tokens[0]->type);
        self::assertSame(0, $tokens[0]->value);
    }

    // === QUOTED STRINGS ===

    #[Test]
    public function parsesQuotedString(): void
    {
        $tokens = $this->tokenizer->tokenize('"hello world"');
        self::assertCount(1, $tokens);
        self::assertSame(TokenType::String_, $tokens[0]->type);
        self::assertSame('hello world', $tokens[0]->value);
    }

    #[Test]
    public function parsesEmptyQuotedString(): void
    {
        $tokens = $this->tokenizer->tokenize('""');
        self::assertSame(TokenType::String_, $tokens[0]->type);
        self::assertSame('', $tokens[0]->value);
    }

    #[Test]
    public function parsesQuotedStringWithEscapedQuote(): void
    {
        $tokens = $this->tokenizer->tokenize('"hello\\"world"');
        self::assertSame('hello"world', $tokens[0]->stringValue());
    }

    #[Test]
    public function parsesQuotedStringWithEscapedBackslash(): void
    {
        $tokens = $this->tokenizer->tokenize('"hello\\\\world"');
        self::assertSame('hello\\world', $tokens[0]->stringValue());
    }

    #[Test]
    public function unterminatedStringThrows(): void
    {
        $this->expectException(ParseException::class);
        $this->tokenizer->tokenize('"unterminated');
    }

    // === LITERALS ===

    #[Test]
    public function parsesLiteral(): void
    {
        $tokens = $this->tokenizer->tokenize("{11}\r\nHello World");
        self::assertCount(1, $tokens);
        self::assertSame(TokenType::Literal, $tokens[0]->type);
        self::assertSame('Hello World', $tokens[0]->value);
    }

    #[Test]
    public function parsesNonSyncLiteral(): void
    {
        $tokens = $this->tokenizer->tokenize("{5+}\r\nHello");
        self::assertSame(TokenType::Literal, $tokens[0]->type);
        self::assertSame('Hello', $tokens[0]->value);
    }

    #[Test]
    public function parsesZeroLengthLiteral(): void
    {
        $tokens = $this->tokenizer->tokenize("{0}\r\n");
        self::assertSame(TokenType::Literal, $tokens[0]->type);
        self::assertSame('', $tokens[0]->value);
    }

    #[Test]
    public function parsesLiteralFollowedByMore(): void
    {
        $tokens = $this->tokenizer->tokenize("{5}\r\nHello NEXT");
        self::assertCount(2, $tokens);
        self::assertSame('Hello', $tokens[0]->stringValue());
        self::assertSame('NEXT', $tokens[1]->stringValue());
    }

    // === LISTS ===

    #[Test]
    public function parsesEmptyList(): void
    {
        $tokens = $this->tokenizer->tokenize('()');
        self::assertCount(1, $tokens);
        self::assertSame(TokenType::List_, $tokens[0]->type);
        self::assertCount(0, $tokens[0]->children);
    }

    #[Test]
    public function parsesListWithItems(): void
    {
        $tokens = $this->tokenizer->tokenize('(FLAGS ENVELOPE)');
        self::assertCount(1, $tokens);
        self::assertTrue($tokens[0]->isList());
        self::assertCount(2, $tokens[0]->children);
        self::assertSame('FLAGS', $tokens[0]->children[0]->stringValue());
        self::assertSame('ENVELOPE', $tokens[0]->children[1]->stringValue());
    }

    #[Test]
    public function parsesNestedLists(): void
    {
        $tokens = $this->tokenizer->tokenize('((a b) (c d))');
        self::assertCount(1, $tokens);
        self::assertTrue($tokens[0]->isList());
        self::assertCount(2, $tokens[0]->children);
        self::assertTrue($tokens[0]->children[0]->isList());
        self::assertSame('a', $tokens[0]->children[0]->children[0]->stringValue());
    }

    #[Test]
    public function parsesListWithFlags(): void
    {
        $tokens = $this->tokenizer->tokenize('(\\Answered \\Flagged \\Deleted \\Seen \\Draft)');
        self::assertCount(1, $tokens);
        self::assertCount(5, $tokens[0]->children);
        self::assertSame('\\Answered', $tokens[0]->children[0]->stringValue());
        self::assertSame('\\Draft', $tokens[0]->children[4]->stringValue());
    }

    #[Test]
    public function unmatchedCloseParenThrows(): void
    {
        $this->expectException(ParseException::class);
        $this->tokenizer->tokenize(')');
    }

    #[Test]
    public function unmatchedOpenParenThrows(): void
    {
        $this->expectException(ParseException::class);
        $this->tokenizer->tokenize('(a b');
    }

    #[Test]
    public function maxDepthThrows(): void
    {
        $nested = str_repeat('(', 26) . 'x' . str_repeat(')', 26);
        $this->expectException(ParseException::class);
        $this->tokenizer->tokenize($nested);
    }

    #[Test]
    public function depth25Succeeds(): void
    {
        $nested = str_repeat('(', 25) . 'x' . str_repeat(')', 25);
        $tokens = $this->tokenizer->tokenize($nested);
        self::assertCount(1, $tokens);
    }

    // === SEQUENCE SETS ===

    #[Test]
    public function parsesSequenceRange(): void
    {
        $tokens = $this->tokenizer->tokenize('1:*');
        self::assertCount(1, $tokens);
        self::assertSame(TokenType::Sequence, $tokens[0]->type);
        self::assertSame('1:*', $tokens[0]->value);
    }

    #[Test]
    public function parsesSequenceList(): void
    {
        $tokens = $this->tokenizer->tokenize('1,3,5:7');
        self::assertSame(TokenType::Sequence, $tokens[0]->type);
        self::assertSame('1,3,5:7', $tokens[0]->value);
    }

    #[Test]
    public function parsesSingleStar(): void
    {
        $tokens = $this->tokenizer->tokenize('*');
        self::assertSame(TokenType::Sequence, $tokens[0]->type);
    }

    #[Test]
    public function singleNumberIsNumber(): void
    {
        $tokens = $this->tokenizer->tokenize('42');
        self::assertSame(TokenType::Number, $tokens[0]->type);
    }

    // === SECTIONS ===

    #[Test]
    public function parsesEmptySection(): void
    {
        $tokens = $this->tokenizer->tokenize('BODY[]');
        self::assertCount(2, $tokens);
        self::assertSame('BODY', $tokens[0]->stringValue());
        self::assertSame(TokenType::Section, $tokens[1]->type);
        self::assertSame('', $tokens[1]->value);
    }

    #[Test]
    public function parsesSectionHeader(): void
    {
        $tokens = $this->tokenizer->tokenize('BODY[HEADER]');
        self::assertSame(TokenType::Section, $tokens[1]->type);
        self::assertSame('HEADER', $tokens[1]->value);
    }

    #[Test]
    public function parsesSectionHeaderFields(): void
    {
        $tokens = $this->tokenizer->tokenize('BODY[HEADER.FIELDS (Subject From)]');
        self::assertSame('HEADER.FIELDS (Subject From)', $tokens[1]->stringValue());
    }

    #[Test]
    public function parsesSectionPartNumber(): void
    {
        $tokens = $this->tokenizer->tokenize('BODY[1.2.MIME]');
        self::assertSame('1.2.MIME', $tokens[1]->stringValue());
    }

    // === PARTIALS ===

    #[Test]
    public function parsesPartial(): void
    {
        $tokens = $this->tokenizer->tokenize('BODY[]<0.1024>');
        self::assertCount(3, $tokens);
        self::assertSame(TokenType::Partial, $tokens[2]->type);
        self::assertSame('0.1024', $tokens[2]->value);
    }

    // === COMPLEX COMMANDS (RFC examples) ===

    #[Test]
    public function parsesLoginCommand(): void
    {
        $tokens = $this->tokenizer->tokenize('A001 LOGIN smith sesame');
        self::assertCount(4, $tokens);
        self::assertSame('A001', $tokens[0]->stringValue());
        self::assertSame('LOGIN', $tokens[1]->stringValue());
        self::assertSame('smith', $tokens[2]->stringValue());
        self::assertSame('sesame', $tokens[3]->stringValue());
    }

    #[Test]
    public function parsesSelectCommand(): void
    {
        $tokens = $this->tokenizer->tokenize('A002 SELECT INBOX');
        self::assertCount(3, $tokens);
        self::assertSame('SELECT', $tokens[1]->stringValue());
        self::assertSame('INBOX', $tokens[2]->stringValue());
    }

    #[Test]
    public function parsesFetchCommand(): void
    {
        $tokens = $this->tokenizer->tokenize('A003 FETCH 1:* (FLAGS ENVELOPE RFC822.SIZE)');
        self::assertCount(4, $tokens);
        self::assertSame('FETCH', $tokens[1]->stringValue());
        self::assertSame(TokenType::Sequence, $tokens[2]->type);
        self::assertTrue($tokens[3]->isList());
        self::assertCount(3, $tokens[3]->children);
    }

    #[Test]
    public function parsesSearchCommand(): void
    {
        $tokens = $this->tokenizer->tokenize('A004 SEARCH UNSEEN FROM "Smith"');
        self::assertCount(5, $tokens);
        self::assertSame('SEARCH', $tokens[1]->stringValue());
        self::assertSame('UNSEEN', $tokens[2]->stringValue());
        self::assertSame('FROM', $tokens[3]->stringValue());
        self::assertSame('Smith', $tokens[4]->stringValue());
    }

    #[Test]
    public function parsesStoreCommand(): void
    {
        $tokens = $this->tokenizer->tokenize('A005 STORE 1:3 +FLAGS (\\Deleted)');
        self::assertCount(5, $tokens);
        self::assertSame('STORE', $tokens[1]->stringValue());
        self::assertSame(TokenType::Sequence, $tokens[2]->type);
        self::assertSame('+FLAGS', $tokens[3]->stringValue());
        self::assertTrue($tokens[4]->isList());
    }

    #[Test]
    public function parsesCopyCommand(): void
    {
        $tokens = $this->tokenizer->tokenize('A006 COPY 2:4 "Saved Messages"');
        self::assertCount(4, $tokens);
        self::assertSame('COPY', $tokens[1]->stringValue());
        self::assertSame('Saved Messages', $tokens[3]->stringValue());
    }

    #[Test]
    public function parsesUidFetchCommand(): void
    {
        $tokens = $this->tokenizer->tokenize('A007 UID FETCH 4827313:4828440 (FLAGS)');
        self::assertCount(5, $tokens);
        self::assertSame('UID', $tokens[1]->stringValue());
        self::assertSame('FETCH', $tokens[2]->stringValue());
    }

    #[Test]
    public function parsesAppendWithLiteral(): void
    {
        $msg = "From: test@example.com\r\nSubject: Test\r\n\r\nBody";
        $input = 'A008 APPEND INBOX (\\Seen) {' . strlen($msg) . "}\r\n" . $msg;
        $tokens = $this->tokenizer->tokenize($input);
        self::assertSame('APPEND', $tokens[1]->stringValue());
        // Find the literal token
        $literalFound = false;
        foreach ($tokens as $t) {
            if ($t->type === TokenType::Literal) {
                self::assertSame($msg, $t->value);
                $literalFound = true;
            }
        }
        self::assertTrue($literalFound, 'Literal token not found');
    }

    // === RESPONSE PARSING ===

    #[Test]
    public function parsesExistsResponse(): void
    {
        $tokens = $this->tokenizer->tokenize('172 EXISTS');
        self::assertCount(2, $tokens);
        self::assertSame(172, $tokens[0]->value);
        self::assertSame('EXISTS', $tokens[1]->stringValue());
    }

    #[Test]
    public function parsesFetchResponse(): void
    {
        $tokens = $this->tokenizer->tokenize('1 FETCH (FLAGS (\\Seen) UID 101)');
        self::assertCount(3, $tokens);
        self::assertSame(1, $tokens[0]->value);
        self::assertSame('FETCH', $tokens[1]->stringValue());
        self::assertTrue($tokens[2]->isList());
    }

    #[Test]
    public function parsesCapabilityResponse(): void
    {
        $tokens = $this->tokenizer->tokenize('CAPABILITY IMAP4rev2 IMAP4rev1 AUTH=PLAIN IDLE MOVE');
        self::assertCount(6, $tokens);
        self::assertSame('CAPABILITY', $tokens[0]->stringValue());
        self::assertSame('IMAP4rev2', $tokens[1]->stringValue());
        self::assertSame('AUTH=PLAIN', $tokens[3]->stringValue());
    }

    // === WHITESPACE / EDGE CASES ===

    #[Test]
    public function skipsLeadingAndTrailingWhitespace(): void
    {
        $tokens = $this->tokenizer->tokenize('  INBOX  ');
        self::assertCount(1, $tokens);
        self::assertSame('INBOX', $tokens[0]->stringValue());
    }

    #[Test]
    public function parsesEmptyInput(): void
    {
        $tokens = $this->tokenizer->tokenize('');
        self::assertCount(0, $tokens);
    }

    #[Test]
    public function skipsCrLf(): void
    {
        $tokens = $this->tokenizer->tokenize("A001 OK\r\n");
        self::assertCount(2, $tokens);
    }

    #[Test]
    public function mixedTypesInOneLine(): void
    {
        $tokens = $this->tokenizer->tokenize('A001 OK NIL 42 "test" (a b) 1:3');
        self::assertCount(7, $tokens);
        self::assertSame(TokenType::Atom, $tokens[0]->type);    // A001
        self::assertSame(TokenType::Atom, $tokens[1]->type);    // OK
        self::assertSame(TokenType::Nil, $tokens[2]->type);     // NIL
        self::assertSame(TokenType::Number, $tokens[3]->type);  // 42
        self::assertSame(TokenType::String_, $tokens[4]->type); // "test"
        self::assertSame(TokenType::List_, $tokens[5]->type);   // (a b)
        self::assertSame(TokenType::Sequence, $tokens[6]->type);// 1:3
    }
}
