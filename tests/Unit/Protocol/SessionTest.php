<?php
/**
 * Tests for IMAP protocol session negotiation state.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

declare(strict_types=1);

namespace PHPdot\Mail\IMAP\Tests\Unit\Protocol;

use PHPdot\Mail\IMAP\Protocol\Session;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SessionTest extends TestCase
{
    #[Test]
    public function defaultsToRev1(): void
    {
        $session = new Session();
        self::assertTrue($session->isRev1());
        self::assertFalse($session->isRev2());
    }

    #[Test]
    public function enableRev2(): void
    {
        $session = new Session();
        $session->enableRev2();
        self::assertTrue($session->isRev2());
        self::assertFalse($session->isRev1());
    }

    #[Test]
    public function enableCondstore(): void
    {
        $session = new Session();
        self::assertFalse($session->isCondstoreEnabled());
        $session->enableCondstore();
        self::assertTrue($session->isCondstoreEnabled());
    }

    #[Test]
    public function enableUtf8(): void
    {
        $session = new Session();
        self::assertFalse($session->isUtf8Enabled());
        $session->enableUtf8();
        self::assertTrue($session->isUtf8Enabled());
    }

    #[Test]
    public function processEnableReturnsEnabled(): void
    {
        $session = new Session();
        $result = $session->processEnable(['CONDSTORE', 'UTF8=ACCEPT', 'UNKNOWN']);
        self::assertContains('CONDSTORE', $result);
        self::assertContains('UTF8=ACCEPT', $result);
        self::assertNotContains('UNKNOWN', $result);
        self::assertTrue($session->isCondstoreEnabled());
        self::assertTrue($session->isUtf8Enabled());
    }

    #[Test]
    public function processEnableDeduplicates(): void
    {
        $session = new Session();
        $first = $session->processEnable(['CONDSTORE']);
        $second = $session->processEnable(['CONDSTORE']);
        self::assertCount(1, $first);
        self::assertCount(0, $second); // already enabled
    }

    #[Test]
    public function processEnableRev2(): void
    {
        $session = new Session();
        $result = $session->processEnable(['IMAP4REV2']);
        self::assertContains('IMAP4REV2', $result);
        self::assertTrue($session->isRev2());
    }

    // === Protocol behavior flags ===

    #[Test]
    public function recentSupportedInRev1Only(): void
    {
        $session = new Session();
        self::assertTrue($session->supportsRecent());

        $session->enableRev2();
        self::assertFalse($session->supportsRecent());
    }

    #[Test]
    public function esearchInRev2Only(): void
    {
        $session = new Session();
        self::assertFalse($session->useEsearch());

        $session->enableRev2();
        self::assertTrue($session->useEsearch());
    }

    #[Test]
    public function utf7MailboxInRev1(): void
    {
        $session = new Session();
        self::assertTrue($session->useUtf7Mailbox());

        // UTF8=ACCEPT disables UTF-7 even in rev1
        $session->enableUtf8();
        self::assertFalse($session->useUtf7Mailbox());
    }

    #[Test]
    public function utf7MailboxDisabledInRev2(): void
    {
        $session = new Session();
        $session->enableRev2();
        self::assertFalse($session->useUtf7Mailbox());
    }

    #[Test]
    public function encodeMailboxRev1(): void
    {
        $session = new Session();
        // Rev1 uses Modified UTF-7
        $encoded = $session->encodeMailbox('日本語');
        self::assertSame('&ZeVnLIqe-', $encoded);
    }

    #[Test]
    public function encodeMailboxRev2(): void
    {
        $session = new Session();
        $session->enableRev2();
        // Rev2 uses UTF-8 as-is
        $encoded = $session->encodeMailbox('日本語');
        self::assertSame('日本語', $encoded);
    }

    #[Test]
    public function decodeMailboxRev1(): void
    {
        $session = new Session();
        $decoded = $session->decodeMailbox('&ZeVnLIqe-');
        self::assertSame('日本語', $decoded);
    }

    #[Test]
    public function decodeMailboxRev2(): void
    {
        $session = new Session();
        $session->enableRev2();
        $decoded = $session->decodeMailbox('日本語');
        self::assertSame('日本語', $decoded);
    }

    #[Test]
    public function compressionTracking(): void
    {
        $session = new Session();
        self::assertFalse($session->isCompressionEnabled());
        $session->enableCompression();
        self::assertTrue($session->isCompressionEnabled());
    }
}
