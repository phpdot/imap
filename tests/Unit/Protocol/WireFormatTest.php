<?php
/**
 * Tests for ENVELOPE, BODYSTRUCTURE, FETCH wire format compilation.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

declare(strict_types=1);

namespace PHPdot\Mail\IMAP\Tests\Unit\Protocol;

use PHPdot\Mail\IMAP\DataType\DTO\Address;
use PHPdot\Mail\IMAP\DataType\DTO\BodyDisposition;
use PHPdot\Mail\IMAP\DataType\DTO\BodyFieldParams;
use PHPdot\Mail\IMAP\DataType\DTO\BodyStructure;
use PHPdot\Mail\IMAP\DataType\DTO\BodyStructureMultipart;
use PHPdot\Mail\IMAP\DataType\DTO\BodyStructurePart;
use PHPdot\Mail\IMAP\DataType\DTO\Envelope;
use PHPdot\Mail\IMAP\DataType\DTO\FetchResult;
use PHPdot\Mail\IMAP\DataType\Enum\ContentEncoding;
use PHPdot\Mail\IMAP\DataType\ValueObject\Flag;
use PHPdot\Mail\IMAP\DataType\ValueObject\ImapDateTime;
use PHPdot\Mail\IMAP\Protocol\WireFormat;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WireFormatTest extends TestCase
{
    // === ADDRESS ===

    #[Test]
    public function addressWireFormat(): void
    {
        $addr = new Address('Fred Foobar', null, 'foobar', 'example.com');
        $wire = WireFormat::address($addr);
        self::assertSame('("Fred Foobar" NIL "foobar" "example.com")', $wire);
    }

    #[Test]
    public function addressNilFields(): void
    {
        $addr = new Address(null, null, null, null);
        self::assertSame('(NIL NIL NIL NIL)', WireFormat::address($addr));
    }

    #[Test]
    public function addressListNil(): void
    {
        self::assertSame('NIL', WireFormat::addressList(null));
        self::assertSame('NIL', WireFormat::addressList([]));
    }

    #[Test]
    public function addressListMultiple(): void
    {
        $addrs = [
            new Address('Alice', null, 'alice', 'example.com'),
            new Address('Bob', null, 'bob', 'example.com'),
        ];
        $wire = WireFormat::addressList($addrs);
        // No space between addresses per ABNF: "(" 1*address ")"
        self::assertStringStartsWith('(("Alice"', $wire);
        self::assertStringEndsWith('"example.com"))', $wire);
        // Should contain both addresses concatenated
        self::assertStringContainsString(')("Bob"', $wire);
    }

    // === ENVELOPE ===

    #[Test]
    public function envelopeWireFormat(): void
    {
        $env = new Envelope(
            date: 'Mon, 7 Feb 1994 21:52:25 -0800',
            subject: 'afternoon meeting',
            from: [new Address('Fred Foobar', null, 'foobar', 'Blurdybloop.COM')],
            sender: [new Address('Fred Foobar', null, 'foobar', 'Blurdybloop.COM')],
            replyTo: [new Address('Fred Foobar', null, 'foobar', 'Blurdybloop.COM')],
            to: [new Address(null, null, 'mostrstrstr', 'example.com')],
            cc: null,
            bcc: null,
            inReplyTo: null,
            messageId: '<B27397-0100000@Blurdybloop.COM>',
        );

        $wire = WireFormat::envelope($env);

        self::assertStringStartsWith('("Mon, 7 Feb 1994', $wire);
        self::assertStringContainsString('"afternoon meeting"', $wire);
        self::assertStringContainsString('"foobar"', $wire);
        self::assertStringContainsString('"Blurdybloop.COM"', $wire);
        self::assertStringContainsString('NIL NIL', $wire); // cc and bcc
        self::assertStringContainsString('"<B27397-0100000@Blurdybloop.COM>"', $wire);
        self::assertStringEndsWith(')', $wire);
    }

    #[Test]
    public function envelopeAllNil(): void
    {
        $env = new Envelope(null, null, null, null, null, null, null, null, null, null);
        $wire = WireFormat::envelope($env);
        self::assertSame('(NIL NIL NIL NIL NIL NIL NIL NIL NIL NIL)', $wire);
    }

    // === BODYSTRUCTURE ===

    #[Test]
    public function bodyStructureSimpleText(): void
    {
        $bs = new BodyStructure(new BodyStructurePart(
            type: 'TEXT',
            subtype: 'PLAIN',
            params: new BodyFieldParams(['CHARSET' => 'US-ASCII']),
            id: null,
            description: null,
            encoding: ContentEncoding::SevenBit,
            size: 3028,
            lines: 92,
        ));

        $wire = WireFormat::bodyStructure($bs);

        self::assertStringStartsWith('("TEXT" "PLAIN"', $wire);
        self::assertStringContainsString('"CHARSET" "US-ASCII"', $wire);
        self::assertStringContainsString('"7BIT"', $wire);
        self::assertStringContainsString('3028', $wire);
        self::assertStringContainsString('92', $wire);
    }

    #[Test]
    public function bodyStructureNonExtensible(): void
    {
        $bs = new BodyStructure(new BodyStructurePart(
            type: 'TEXT',
            subtype: 'PLAIN',
            encoding: ContentEncoding::SevenBit,
            size: 100,
            lines: 5,
            md5: 'abc123',
        ));

        $extensible = WireFormat::bodyStructure($bs, true);
        $nonExtensible = WireFormat::bodyStructure($bs, false);

        // Extensible includes md5, non-extensible does not
        self::assertStringContainsString('"abc123"', $extensible);
        self::assertStringNotContainsString('abc123', $nonExtensible);
    }

    #[Test]
    public function bodyStructureMultipart(): void
    {
        $text = new BodyStructure(new BodyStructurePart(
            type: 'TEXT',
            subtype: 'PLAIN',
            encoding: ContentEncoding::SevenBit,
            size: 100,
            lines: 5,
        ));
        $html = new BodyStructure(new BodyStructurePart(
            type: 'TEXT',
            subtype: 'HTML',
            encoding: ContentEncoding::QuotedPrintable,
            size: 500,
            lines: 20,
        ));

        $bs = new BodyStructure(new BodyStructureMultipart(
            parts: [$text, $html],
            subtype: 'ALTERNATIVE',
        ));

        $wire = WireFormat::bodyStructure($bs);

        // Multipart: 1*body SP media-subtype
        // Bodies are concatenated without space per ABNF
        self::assertStringStartsWith('(("TEXT" "PLAIN"', $wire);
        self::assertStringContainsString('"ALTERNATIVE"', $wire);
    }

    #[Test]
    public function bodyStructureWithDisposition(): void
    {
        $bs = new BodyStructure(new BodyStructurePart(
            type: 'APPLICATION',
            subtype: 'PDF',
            encoding: ContentEncoding::Base64,
            size: 50000,
            disposition: new BodyDisposition('ATTACHMENT', new BodyFieldParams(['FILENAME' => 'report.pdf'])),
        ));

        $wire = WireFormat::bodyStructure($bs);
        self::assertStringContainsString('"ATTACHMENT"', $wire);
        self::assertStringContainsString('"FILENAME" "report.pdf"', $wire);
    }

    #[Test]
    public function bodyStructureMessageRfc822(): void
    {
        $innerEnvelope = new Envelope(
            'Mon, 7 Feb 1994 21:52:25 -0800',
            'inner',
            null, null, null, null, null, null, null, null,
        );
        $innerBody = new BodyStructure(new BodyStructurePart(
            type: 'TEXT', subtype: 'PLAIN',
            encoding: ContentEncoding::SevenBit, size: 100, lines: 5,
        ));

        $bs = new BodyStructure(new BodyStructurePart(
            type: 'MESSAGE',
            subtype: 'RFC822',
            encoding: ContentEncoding::SevenBit,
            size: 2000,
            lines: 50,
            envelope: $innerEnvelope,
            bodyStructure: $innerBody,
        ));

        $wire = WireFormat::bodyStructure($bs);
        self::assertStringContainsString('"MESSAGE" "RFC822"', $wire);
        self::assertStringContainsString('"inner"', $wire); // inner envelope subject
    }

    // === FETCH RESPONSE ===

    #[Test]
    public function fetchResponseBasic(): void
    {
        $result = new FetchResult(
            sequenceNumber: 1,
            uid: 101,
            flags: [new Flag('\\Seen'), new Flag('\\Flagged')],
            rfc822Size: 4423,
        );

        $wire = WireFormat::fetchResponse($result);

        self::assertStringStartsWith('* 1 FETCH (', $wire);
        self::assertStringContainsString('UID 101', $wire);
        self::assertStringContainsString('FLAGS (\\Seen \\Flagged)', $wire);
        self::assertStringContainsString('RFC822.SIZE 4423', $wire);
        self::assertStringEndsWith(")\r\n", $wire);
    }

    #[Test]
    public function fetchResponseWithEnvelope(): void
    {
        $result = new FetchResult(
            sequenceNumber: 1,
            envelope: new Envelope(
                'Mon, 7 Feb 1994 21:52:25 -0800',
                'Test Subject',
                [new Address('Test', null, 'test', 'example.com')],
                null, null, null, null, null, null, null,
            ),
        );

        $wire = WireFormat::fetchResponse($result);
        self::assertStringContainsString('ENVELOPE (', $wire);
        self::assertStringContainsString('"Test Subject"', $wire);
    }

    #[Test]
    public function fetchResponseWithBodySection(): void
    {
        $result = new FetchResult(
            sequenceNumber: 2,
            bodySections: [
                'HEADER' => "From: test@example.com\r\nSubject: Hi\r\n",
                'TEXT' => "Hello World",
            ],
        );

        $wire = WireFormat::fetchResponse($result);
        self::assertStringContainsString('BODY[HEADER] {', $wire);
        self::assertStringContainsString('BODY[TEXT] {11}', $wire);
    }

    #[Test]
    public function fetchResponseWithNilBodySection(): void
    {
        $result = new FetchResult(
            sequenceNumber: 1,
            bodySections: ['1.2' => null],
        );

        $wire = WireFormat::fetchResponse($result);
        self::assertStringContainsString('BODY[1.2] NIL', $wire);
    }

    #[Test]
    public function fetchResponseWithInternalDate(): void
    {
        $result = new FetchResult(
            sequenceNumber: 1,
            internalDate: ImapDateTime::fromDateTime('25-Mar-2026 14:30:00 +0000'),
        );

        $wire = WireFormat::fetchResponse($result);
        self::assertStringContainsString('INTERNALDATE "', $wire);
        self::assertStringContainsString('25-Mar-2026', $wire);
    }

    #[Test]
    public function fetchResponseWithModseq(): void
    {
        $result = new FetchResult(
            sequenceNumber: 1,
            modseq: 12345,
        );

        $wire = WireFormat::fetchResponse($result);
        self::assertStringContainsString('MODSEQ (12345)', $wire);
    }

    // === NSTRING / QUOTED ===

    #[Test]
    public function nstringNil(): void
    {
        self::assertSame('NIL', WireFormat::nstring(null));
    }

    #[Test]
    public function nstringQuoted(): void
    {
        self::assertSame('"hello"', WireFormat::nstring('hello'));
    }

    #[Test]
    public function quotedEscapes(): void
    {
        self::assertSame('"hello\\\\"', WireFormat::quoted('hello\\'));
        self::assertSame('"hello\\"world"', WireFormat::quoted('hello"world'));
    }

    #[Test]
    public function quotedUsesLiteralForBinary(): void
    {
        $result = WireFormat::quoted("hello\r\nworld");
        self::assertStringStartsWith('{', $result);
    }

    // === BODY FIELD PARAMS ===

    #[Test]
    public function bodyFieldParamsNil(): void
    {
        self::assertSame('NIL', WireFormat::bodyFieldParams(null));
        self::assertSame('NIL', WireFormat::bodyFieldParams(new BodyFieldParams([])));
    }

    #[Test]
    public function bodyFieldParamsFormatted(): void
    {
        $params = new BodyFieldParams(['CHARSET' => 'UTF-8', 'NAME' => 'test.txt']);
        $wire = WireFormat::bodyFieldParams($params);
        self::assertSame('("CHARSET" "UTF-8" "NAME" "test.txt")', $wire);
    }

    // === BODY LANGUAGE ===

    #[Test]
    public function bodyLanguageNil(): void
    {
        self::assertSame('NIL', WireFormat::bodyLanguage(null));
    }

    #[Test]
    public function bodyLanguageSingle(): void
    {
        self::assertSame('"EN"', WireFormat::bodyLanguage('EN'));
    }

    #[Test]
    public function bodyLanguageMultiple(): void
    {
        self::assertSame('("EN" "FR")', WireFormat::bodyLanguage(['EN', 'FR']));
    }
}
