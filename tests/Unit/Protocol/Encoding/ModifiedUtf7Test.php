<?php
/**
 * Tests for Modified UTF-7 encoding/decoding.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

declare(strict_types=1);

namespace PHPdot\Mail\IMAP\Tests\Unit\Protocol\Encoding;

use PHPdot\Mail\IMAP\Exception\EncodingException;
use PHPdot\Mail\IMAP\Protocol\Encoding\ModifiedUtf7;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ModifiedUtf7Test extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function encodingProvider(): array
    {
        return [
            'plain ASCII' => ['INBOX', 'INBOX'],
            'ampersand' => ['Tom & Jerry', 'Tom &- Jerry'],
            'Japanese' => ['日本語', '&ZeVnLIqe-'],
            'mixed' => ['INBOX/日本語', 'INBOX/&ZeVnLIqe-'],
            'path with non-ASCII' => ['Langstrasse/Zürich', 'Langstrasse/Z&APw-rich'],
            'empty string' => ['', ''],
            'all ASCII printable' => ['Hello World!', 'Hello World!'],
        ];
    }

    #[Test]
    #[DataProvider('encodingProvider')]
    public function encodeDecodeRoundTrip(string $utf8, string $encoded): void
    {
        self::assertSame($encoded, ModifiedUtf7::encode($utf8));
        self::assertSame($utf8, ModifiedUtf7::decode($encoded));
    }

    #[Test]
    public function decodeInvalidThrows(): void
    {
        $this->expectException(EncodingException::class);
        // Unterminated modified base64 sequence
        ModifiedUtf7::decode('&ZeVnLIqe');
    }

    #[Test]
    public function decodeAmpersandDash(): void
    {
        self::assertSame('&', ModifiedUtf7::decode('&-'));
    }

    #[Test]
    public function encodePlainAsciiPassesThrough(): void
    {
        $plain = 'INBOX/Sent/Drafts';
        self::assertSame($plain, ModifiedUtf7::encode($plain));
        self::assertSame($plain, ModifiedUtf7::decode($plain));
    }
}
