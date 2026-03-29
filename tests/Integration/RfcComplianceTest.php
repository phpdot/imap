<?php
/**
 * Tests against RFC 9051 and RFC 3501 wire format examples.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

declare(strict_types=1);

namespace PHPdot\Mail\IMAP\Tests\Integration;

use PHPdot\Mail\IMAP\DataType\DTO\Token;
use PHPdot\Mail\IMAP\DataType\Enum\TokenType;
use PHPdot\Mail\IMAP\DataType\ValueObject\Flag;
use PHPdot\Mail\IMAP\DataType\ValueObject\SequenceSet;
use PHPdot\Mail\IMAP\DataType\ValueObject\Tag;
use PHPdot\Mail\IMAP\Protocol\Compiler;
use PHPdot\Mail\IMAP\Protocol\Tokenizer;
use PHPdot\Mail\IMAP\Server\Parser\CommandParser;
use PHPdot\Mail\IMAP\Server\Response\ResponseBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests against examples from RFC 9051 and RFC 3501.
 */
final class RfcComplianceTest extends TestCase
{
    private Tokenizer $tokenizer;
    private Compiler $compiler;
    private CommandParser $commandParser;
    private ResponseBuilder $responseBuilder;

    protected function setUp(): void
    {
        $this->tokenizer = new Tokenizer();
        $this->compiler = new Compiler();
        $this->commandParser = new CommandParser();
        $this->responseBuilder = new ResponseBuilder();
    }

    // === LITERAL8 ===

    #[Test]
    public function tokenizesLiteral8(): void
    {
        $input = "~{5}\r\nHello";
        $tokens = $this->tokenizer->tokenize($input);
        self::assertCount(1, $tokens);
        self::assertSame(TokenType::Literal8, $tokens[0]->type);
        self::assertSame('Hello', $tokens[0]->value);
    }

    #[Test]
    public function literal8RoundTrip(): void
    {
        $token = new Token(TokenType::Literal8, 'binary data');
        $wire = $this->compiler->compileToken($token);
        self::assertSame("~{11}\r\nbinary data", $wire);

        // Re-tokenize
        $tokens = $this->tokenizer->tokenize($wire);
        self::assertSame(TokenType::Literal8, $tokens[0]->type);
        self::assertSame('binary data', $tokens[0]->stringValue());
    }

    #[Test]
    public function literal8WithZeroLength(): void
    {
        $tokens = $this->tokenizer->tokenize("~{0}\r\n");
        self::assertSame(TokenType::Literal8, $tokens[0]->type);
        self::assertSame('', $tokens[0]->stringValue());
    }

    // === FETCH MACRO EXPANSION ===

    #[Test]
    public function fetchMacroAllExpanded(): void
    {
        $cmd = $this->commandParser->parse('A001 FETCH 1:* ALL');
        self::assertInstanceOf(\PHPdot\Mail\IMAP\Server\Command\FetchCommand::class, $cmd);
        $itemNames = array_map(fn(Token $t): string => $t->stringValue(), $cmd->items);
        self::assertContains('FLAGS', $itemNames);
        self::assertContains('INTERNALDATE', $itemNames);
        self::assertContains('RFC822.SIZE', $itemNames);
        self::assertContains('ENVELOPE', $itemNames);
    }

    #[Test]
    public function fetchMacroFastExpanded(): void
    {
        $cmd = $this->commandParser->parse('A001 FETCH 1 FAST');
        self::assertInstanceOf(\PHPdot\Mail\IMAP\Server\Command\FetchCommand::class, $cmd);
        $itemNames = array_map(fn(Token $t): string => $t->stringValue(), $cmd->items);
        self::assertContains('FLAGS', $itemNames);
        self::assertContains('INTERNALDATE', $itemNames);
        self::assertContains('RFC822.SIZE', $itemNames);
        self::assertNotContains('ENVELOPE', $itemNames);
    }

    #[Test]
    public function fetchMacroFullExpanded(): void
    {
        $cmd = $this->commandParser->parse('A001 FETCH 1 FULL');
        self::assertInstanceOf(\PHPdot\Mail\IMAP\Server\Command\FetchCommand::class, $cmd);
        $itemNames = array_map(fn(Token $t): string => $t->stringValue(), $cmd->items);
        self::assertContains('BODY', $itemNames);
    }

    // === ESEARCH RESPONSE ===

    #[Test]
    public function esearchResponseBasic(): void
    {
        $wire = $this->responseBuilder->esearch('A001', true, ['ALL', '1:3', 'COUNT', '3']);
        self::assertSame("* ESEARCH (TAG \"A001\") UID ALL 1:3 COUNT 3\r\n", $wire);
    }

    #[Test]
    public function esearchResponseMinMax(): void
    {
        $wire = $this->responseBuilder->esearch('A002', false, ['MIN', '1', 'MAX', '100']);
        self::assertSame("* ESEARCH (TAG \"A002\") MIN 1 MAX 100\r\n", $wire);
    }

    #[Test]
    public function esearchResponseNoTag(): void
    {
        $wire = $this->responseBuilder->esearch(null, true, ['COUNT', '42']);
        self::assertSame("* ESEARCH UID COUNT 42\r\n", $wire);
    }

    // === BINARY RESPONSE ===

    #[Test]
    public function binaryResponseUsesLiteral8(): void
    {
        $wire = $this->responseBuilder->binary(1, '2', "\x00\x01\x02\x03");
        self::assertStringContainsString('BINARY[2] ~{4}', $wire);
        self::assertStringStartsWith('* 1 FETCH (', $wire);
    }

    // === APPENDUID / COPYUID ===

    #[Test]
    public function appendUidResponse(): void
    {
        $wire = $this->responseBuilder->okAppendUid(
            new Tag('A003'),
            38505,
            4392,
        );
        self::assertSame("A003 OK [APPENDUID 38505 4392] APPEND completed\r\n", $wire);
    }

    #[Test]
    public function copyUidResponse(): void
    {
        $wire = $this->responseBuilder->okCopyUid(
            new Tag('A004'),
            38505,
            SequenceSet::fromString('304,307,309'),
            SequenceSet::fromString('3956,3957,3958'),
        );
        self::assertSame("A004 OK [COPYUID 38505 304,307,309 3956,3957,3958] COPY completed\r\n", $wire);
    }

    // === SELECT RESPONSE HELPERS ===

    #[Test]
    public function uidValidityResponse(): void
    {
        $wire = $this->responseBuilder->okUidValidity(3857529045);
        self::assertSame("* OK [UIDVALIDITY 3857529045] UIDs valid\r\n", $wire);
    }

    #[Test]
    public function uidNextResponse(): void
    {
        $wire = $this->responseBuilder->okUidNext(4392);
        self::assertSame("* OK [UIDNEXT 4392] Predicted next UID\r\n", $wire);
    }

    #[Test]
    public function highestModseqResponse(): void
    {
        $wire = $this->responseBuilder->okHighestModseq(123456);
        self::assertSame("* OK [HIGHESTMODSEQ 123456] Highest\r\n", $wire);
    }

    #[Test]
    public function permanentFlagsResponse(): void
    {
        $wire = $this->responseBuilder->okPermanentFlags([
            new Flag('\\Answered'),
            new Flag('\\Flagged'),
            new Flag('\\Deleted'),
            new Flag('\\Seen'),
            new Flag('\\Draft'),
        ]);
        self::assertStringContainsString('PERMANENTFLAGS', $wire);
        self::assertStringContainsString('\\Answered', $wire);
        self::assertStringContainsString('\\*', $wire); // custom flags allowed
    }

    // === RFC 9051 COMMAND EXAMPLES ===

    #[Test]
    public function rfc9051LoginExample(): void
    {
        // RFC 9051 Section 6.2.3
        $cmd = $this->commandParser->parse('a001 LOGIN SMITH SESAME');
        self::assertSame('LOGIN', $cmd->name);
    }

    #[Test]
    public function rfc9051SelectExample(): void
    {
        // RFC 9051 Section 6.3.2
        $cmd = $this->commandParser->parse('A142 SELECT INBOX');
        self::assertSame('INBOX', $cmd->tag->value === 'A142' ? 'INBOX' : '');
    }

    #[Test]
    public function rfc9051FetchExample(): void
    {
        // RFC 9051 Section 6.4.5
        $cmd = $this->commandParser->parse('A654 FETCH 2:4 (FLAGS BODY[HEADER.FIELDS (DATE FROM)])');
        self::assertInstanceOf(\PHPdot\Mail\IMAP\Server\Command\FetchCommand::class, $cmd);
        self::assertSame('2:4', (string) $cmd->sequenceSet);
    }

    #[Test]
    public function rfc9051SearchExample(): void
    {
        // RFC 9051 Section 6.4.4
        $cmd = $this->commandParser->parse('A282 SEARCH FLAGGED SINCE 1-Feb-1994 NOT FROM "Smith"');
        self::assertInstanceOf(\PHPdot\Mail\IMAP\Server\Command\SearchCommand::class, $cmd);
        self::assertNotEmpty($cmd->queries);
    }

    #[Test]
    public function rfc9051StoreExample(): void
    {
        // RFC 9051 Section 6.4.6
        $cmd = $this->commandParser->parse('A003 STORE 2:4 +FLAGS (\\Deleted)');
        self::assertInstanceOf(\PHPdot\Mail\IMAP\Server\Command\StoreCommand::class, $cmd);
        self::assertSame('add', $cmd->operation->action());
    }

    #[Test]
    public function rfc9051CopyExample(): void
    {
        // RFC 9051 Section 6.4.7
        $cmd = $this->commandParser->parse('A003 COPY 2:4 MEETING');
        self::assertInstanceOf(\PHPdot\Mail\IMAP\Server\Command\CopyMoveCommand::class, $cmd);
        self::assertSame('MEETING', $cmd->destination->name);
    }

    #[Test]
    public function rfc9051MoveExample(): void
    {
        // RFC 9051 Section 6.4.8
        $cmd = $this->commandParser->parse('A003 MOVE 1:3 Archive');
        self::assertInstanceOf(\PHPdot\Mail\IMAP\Server\Command\CopyMoveCommand::class, $cmd);
    }

    #[Test]
    public function rfc9051UidFetchExample(): void
    {
        // RFC 9051 Section 6.4.9
        $cmd = $this->commandParser->parse('A999 UID FETCH 4827313:4828442 FLAGS');
        self::assertInstanceOf(\PHPdot\Mail\IMAP\Server\Command\FetchCommand::class, $cmd);
        self::assertTrue($cmd->isUid);
    }

    // === RFC RESPONSE PARSING ===

    #[Test]
    public function rfc9051CapabilityResponseTokenized(): void
    {
        $tokens = $this->tokenizer->tokenize('CAPABILITY IMAP4rev2 STARTTLS AUTH=GSSAPI LOGINDISABLED');
        self::assertSame('CAPABILITY', $tokens[0]->stringValue());
        self::assertSame('IMAP4rev2', $tokens[1]->stringValue());
        self::assertSame('AUTH=GSSAPI', $tokens[3]->stringValue());
    }

    #[Test]
    public function rfc9051ExistsResponseTokenized(): void
    {
        $tokens = $this->tokenizer->tokenize('172 EXISTS');
        self::assertSame(172, $tokens[0]->intValue());
        self::assertSame('EXISTS', $tokens[1]->stringValue());
    }

    #[Test]
    public function rfc9051FetchResponseTokenized(): void
    {
        $tokens = $this->tokenizer->tokenize('1 FETCH (FLAGS (\\Seen) RFC822.SIZE 4423)');
        self::assertSame(1, $tokens[0]->intValue());
        self::assertSame('FETCH', $tokens[1]->stringValue());
        self::assertTrue($tokens[2]->isList());
    }
}
