<?php
/**
 * Tests for parsing every IMAP server response type.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

declare(strict_types=1);

namespace PHPdot\Mail\IMAP\Tests\Unit\Client;

use PHPdot\Mail\IMAP\Client\Parser\ResponseParser;
use PHPdot\Mail\IMAP\Client\Response\ContinuationResponse;
use PHPdot\Mail\IMAP\Client\Response\DataResponse;
use PHPdot\Mail\IMAP\Client\Response\GreetingResponse;
use PHPdot\Mail\IMAP\Client\Response\TaggedResponse;
use PHPdot\Mail\IMAP\DataType\Enum\GreetingStatus;
use PHPdot\Mail\IMAP\DataType\Enum\ResponseCode;
use PHPdot\Mail\IMAP\DataType\Enum\ResponseStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ResponseParserTest extends TestCase
{
    // === GREETING ===

    #[Test]
    public function parsesOkGreeting(): void
    {
        $parser = new ResponseParser();
        $response = $parser->parse('* OK IMAP4rev2 Server ready');
        self::assertInstanceOf(GreetingResponse::class, $response);
        self::assertSame(GreetingStatus::Ok, $response->status);
        self::assertStringContainsString('Server ready', $response->responseText->text);
    }

    #[Test]
    public function parsesGreetingWithCapability(): void
    {
        $parser = new ResponseParser();
        $response = $parser->parse('* OK [CAPABILITY IMAP4rev2 AUTH=PLAIN] Server ready');
        self::assertInstanceOf(GreetingResponse::class, $response);
        self::assertSame(ResponseCode::Capability, $response->responseText->code);
    }

    #[Test]
    public function parsesPreauthGreeting(): void
    {
        $parser = new ResponseParser();
        $response = $parser->parse('* PREAUTH Authenticated');
        self::assertInstanceOf(GreetingResponse::class, $response);
        self::assertSame(GreetingStatus::PreAuth, $response->status);
    }

    // === TAGGED RESPONSES ===

    #[Test]
    public function parsesTaggedOk(): void
    {
        $parser = new ResponseParser();
        $parser->parse('* OK greeting'); // consume greeting
        $response = $parser->parse('A001 OK LOGIN completed');
        self::assertInstanceOf(TaggedResponse::class, $response);
        self::assertSame('A001', $response->tag->value);
        self::assertSame(ResponseStatus::Ok, $response->status);
        self::assertStringContainsString('LOGIN completed', $response->responseText->text);
    }

    #[Test]
    public function parsesTaggedNo(): void
    {
        $parser = new ResponseParser();
        $parser->parse('* OK greeting');
        $response = $parser->parse('A001 NO [AUTHENTICATIONFAILED] Invalid credentials');
        self::assertInstanceOf(TaggedResponse::class, $response);
        self::assertTrue($response->isNo());
        self::assertSame(ResponseCode::AuthenticationFailed, $response->responseText->code);
    }

    #[Test]
    public function parsesTaggedBad(): void
    {
        $parser = new ResponseParser();
        $parser->parse('* OK greeting');
        $response = $parser->parse('A001 BAD Command unknown');
        self::assertInstanceOf(TaggedResponse::class, $response);
        self::assertTrue($response->isBad());
    }

    #[Test]
    public function parsesTaggedWithResponseCode(): void
    {
        $parser = new ResponseParser();
        $parser->parse('* OK greeting');
        $response = $parser->parse('A002 OK [READ-WRITE] SELECT completed');
        self::assertInstanceOf(TaggedResponse::class, $response);
        self::assertSame(ResponseCode::ReadWrite, $response->responseText->code);
    }

    #[Test]
    public function parsesTaggedWithCodeData(): void
    {
        $parser = new ResponseParser();
        $parser->parse('* OK greeting');
        $response = $parser->parse('A001 OK [APPENDUID 38505 4392] APPEND completed');
        self::assertInstanceOf(TaggedResponse::class, $response);
        self::assertSame(ResponseCode::AppendUid, $response->responseText->code);
        self::assertSame(['38505', '4392'], $response->responseText->codeData);
    }

    // === CONTINUATION ===

    #[Test]
    public function parsesContinuation(): void
    {
        $parser = new ResponseParser();
        $parser->parse('* OK greeting');
        $response = $parser->parse('+ Ready for literal data');
        self::assertInstanceOf(ContinuationResponse::class, $response);
        self::assertSame('Ready for literal data', $response->text);
    }

    #[Test]
    public function parsesEmptyContinuation(): void
    {
        $parser = new ResponseParser();
        $parser->parse('* OK greeting');
        $response = $parser->parse('+');
        self::assertInstanceOf(ContinuationResponse::class, $response);
    }

    // === UNTAGGED DATA ===

    #[Test]
    public function parsesExists(): void
    {
        $parser = new ResponseParser();
        $parser->parse('* OK greeting');
        $response = $parser->parse('* 172 EXISTS');
        self::assertInstanceOf(DataResponse::class, $response);
        self::assertTrue($response->isExists());
        self::assertSame(172, $response->number);
    }

    #[Test]
    public function parsesRecent(): void
    {
        $parser = new ResponseParser();
        $parser->parse('* OK greeting');
        $response = $parser->parse('* 3 RECENT');
        self::assertInstanceOf(DataResponse::class, $response);
        self::assertTrue($response->isRecent());
        self::assertSame(3, $response->number);
    }

    #[Test]
    public function parsesExpunge(): void
    {
        $parser = new ResponseParser();
        $parser->parse('* OK greeting');
        $response = $parser->parse('* 5 EXPUNGE');
        self::assertInstanceOf(DataResponse::class, $response);
        self::assertTrue($response->isExpunge());
        self::assertSame(5, $response->number);
    }

    #[Test]
    public function parsesFetch(): void
    {
        $parser = new ResponseParser();
        $parser->parse('* OK greeting');
        $response = $parser->parse('* 1 FETCH (FLAGS (\\Seen) UID 101)');
        self::assertInstanceOf(DataResponse::class, $response);
        self::assertTrue($response->isFetch());
        self::assertSame(1, $response->number);
        self::assertNotEmpty($response->tokens);
    }

    #[Test]
    public function parsesCapability(): void
    {
        $parser = new ResponseParser();
        $parser->parse('* OK greeting');
        $response = $parser->parse('* CAPABILITY IMAP4rev2 IDLE AUTH=PLAIN');
        self::assertInstanceOf(DataResponse::class, $response);
        self::assertTrue($response->isCapability());
    }

    #[Test]
    public function parsesFlags(): void
    {
        $parser = new ResponseParser();
        $parser->parse('* OK greeting');
        $response = $parser->parse('* FLAGS (\\Answered \\Flagged \\Deleted \\Seen \\Draft)');
        self::assertInstanceOf(DataResponse::class, $response);
        self::assertTrue($response->isFlags());
    }

    #[Test]
    public function parsesList(): void
    {
        $parser = new ResponseParser();
        $parser->parse('* OK greeting');
        $response = $parser->parse('* LIST (\\HasNoChildren) "/" INBOX');
        self::assertInstanceOf(DataResponse::class, $response);
        self::assertTrue($response->isList());
    }

    #[Test]
    public function parsesStatus(): void
    {
        $parser = new ResponseParser();
        $parser->parse('* OK greeting');
        $response = $parser->parse('* STATUS INBOX (MESSAGES 172 UIDNEXT 4392 UNSEEN 12)');
        self::assertInstanceOf(DataResponse::class, $response);
        self::assertTrue($response->isStatus());
    }

    #[Test]
    public function parsesSearch(): void
    {
        $parser = new ResponseParser();
        $parser->parse('* OK greeting');
        $response = $parser->parse('* SEARCH 2 84 882');
        self::assertInstanceOf(DataResponse::class, $response);
        self::assertTrue($response->isSearch());
    }

    #[Test]
    public function parsesBye(): void
    {
        $parser = new ResponseParser();
        $parser->parse('* OK greeting');
        $response = $parser->parse('* BYE Server shutting down');
        self::assertInstanceOf(DataResponse::class, $response);
        self::assertTrue($response->isBye());
    }

    #[Test]
    public function parsesNamespace(): void
    {
        $parser = new ResponseParser();
        $parser->parse('* OK greeting');
        $response = $parser->parse('* NAMESPACE (("" "/")) NIL NIL');
        self::assertInstanceOf(DataResponse::class, $response);
        self::assertTrue($response->isNamespace());
    }

    #[Test]
    public function parsesEnabled(): void
    {
        $parser = new ResponseParser();
        $parser->parse('* OK greeting');
        $response = $parser->parse('* ENABLED CONDSTORE');
        self::assertInstanceOf(DataResponse::class, $response);
        self::assertTrue($response->isEnabled());
    }

    #[Test]
    public function parsesUntaggedOkWithCode(): void
    {
        $parser = new ResponseParser();
        $parser->parse('* OK greeting');
        $response = $parser->parse('* OK [UIDVALIDITY 3857529045] UIDs valid');
        self::assertInstanceOf(DataResponse::class, $response);
        self::assertTrue($response->isOk());
    }
}
