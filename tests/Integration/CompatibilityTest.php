<?php
/**
 * Tests for IMAP4rev1/rev2 protocol negotiation.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

declare(strict_types=1);

namespace PHPdot\Mail\IMAP\Tests\Integration;

use PHPdot\Mail\IMAP\Client\ClientProtocol;
use PHPdot\Mail\IMAP\Client\Event\DataEvent;
use PHPdot\Mail\IMAP\Client\Event\GreetingEvent;
use PHPdot\Mail\IMAP\Client\Event\TaggedResponseEvent;
use PHPdot\Mail\IMAP\Server\Event\LoginEvent;
use PHPdot\Mail\IMAP\Server\Event\SimpleEvent;
use PHPdot\Mail\IMAP\Server\ServerProtocol;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests IMAP4rev1/rev2 compatibility negotiation.
 */
final class CompatibilityTest extends TestCase
{
    #[Test]
    public function serverDefaultsToRev1Session(): void
    {
        $server = new ServerProtocol();
        self::assertTrue($server->session()->isRev1());
        self::assertFalse($server->session()->isRev2());
    }

    #[Test]
    public function serverEnableRev2ViaCommand(): void
    {
        $server = new ServerProtocol();
        $server->on(LoginEvent::class, fn(LoginEvent $e) => $e->accept());
        $server->on(SimpleEvent::class, fn(SimpleEvent $e) => $e->accept());

        // Login first (ENABLE only valid in Authenticated state)
        $server->onData("A001 LOGIN user pass\r\n");

        // ENABLE IMAP4rev2
        $responses = $server->onData("A002 ENABLE IMAP4REV2 CONDSTORE\r\n");

        // Should get ENABLED response
        $enabledFound = false;
        foreach ($responses as $r) {
            if (str_contains($r, 'ENABLED')) {
                $enabledFound = true;
                self::assertStringContainsString('IMAP4REV2', $r);
                self::assertStringContainsString('CONDSTORE', $r);
            }
        }
        self::assertTrue($enabledFound, 'ENABLED response not found');

        // Session should reflect rev2
        self::assertTrue($server->session()->isRev2());
        self::assertTrue($server->session()->isCondstoreEnabled());
    }

    #[Test]
    public function serverEnableUtf8Accept(): void
    {
        $server = new ServerProtocol();
        $server->on(LoginEvent::class, fn(LoginEvent $e) => $e->accept());

        $server->onData("A001 LOGIN user pass\r\n");
        $server->onData("A002 ENABLE UTF8=ACCEPT\r\n");

        self::assertTrue($server->session()->isUtf8Enabled());
        self::assertFalse($server->session()->useUtf7Mailbox());
    }

    #[Test]
    public function serverEnableDeduplicates(): void
    {
        $server = new ServerProtocol();
        $server->on(LoginEvent::class, fn(LoginEvent $e) => $e->accept());

        $server->onData("A001 LOGIN user pass\r\n");

        // Enable CONDSTORE
        $responses1 = $server->onData("A002 ENABLE CONDSTORE\r\n");
        $enabled1 = '';
        foreach ($responses1 as $r) {
            if (str_contains($r, 'ENABLED')) {
                $enabled1 = $r;
            }
        }
        self::assertStringContainsString('CONDSTORE', $enabled1);

        // Enable again — should return empty ENABLED
        $responses2 = $server->onData("A003 ENABLE CONDSTORE\r\n");
        $enabled2 = '';
        foreach ($responses2 as $r) {
            if (str_contains($r, 'ENABLED')) {
                $enabled2 = $r;
            }
        }
        self::assertStringNotContainsString('CONDSTORE', $enabled2);
    }

    #[Test]
    public function serverSelectCondstoreEnablesCondstore(): void
    {
        $server = new ServerProtocol();
        $server->on(LoginEvent::class, fn(LoginEvent $e) => $e->accept());
        $server->on(\PHPdot\Mail\IMAP\Server\Event\SelectEvent::class, fn($e) => $e->accept());

        $server->onData("A001 LOGIN user pass\r\n");
        $server->onData("A002 SELECT INBOX (CONDSTORE)\r\n");

        self::assertTrue($server->session()->isCondstoreEnabled());
    }

    #[Test]
    public function serverRev1SessionUsesUtf7(): void
    {
        $server = new ServerProtocol();
        // Default rev1
        self::assertTrue($server->session()->useUtf7Mailbox());
        self::assertSame('&ZeVnLIqe-', $server->session()->encodeMailbox('日本語'));
    }

    #[Test]
    public function serverRev2SessionUsesUtf8(): void
    {
        $server = new ServerProtocol();
        $server->on(LoginEvent::class, fn(LoginEvent $e) => $e->accept());

        $server->onData("A001 LOGIN user pass\r\n");
        $server->onData("A002 ENABLE IMAP4REV2\r\n");

        self::assertFalse($server->session()->useUtf7Mailbox());
        self::assertSame('日本語', $server->session()->encodeMailbox('日本語'));
    }

    // === CLIENT SIDE ===

    #[Test]
    public function clientDetectsRev2FromGreeting(): void
    {
        $client = new ClientProtocol();
        $client->on(GreetingEvent::class, function () {});

        $client->onData("* OK [CAPABILITY IMAP4rev2 IMAP4rev1 AUTH=PLAIN] Server ready\r\n");

        self::assertTrue($client->serverSupportsRev2());
    }

    #[Test]
    public function clientDetectsRev1OnlyServer(): void
    {
        $client = new ClientProtocol();
        $client->on(GreetingEvent::class, function () {});

        $client->onData("* OK [CAPABILITY IMAP4rev1 AUTH=PLAIN IDLE] Server ready\r\n");

        self::assertFalse($client->serverSupportsRev2());
    }

    #[Test]
    public function clientDetectsCapabilityFromUntagged(): void
    {
        $client = new ClientProtocol();
        $client->on(GreetingEvent::class, function () {});
        $client->on(DataEvent::class, function () {});

        // Greeting without capability
        $client->onData("* OK Server ready\r\n");
        self::assertFalse($client->serverSupportsRev2());

        // Untagged CAPABILITY
        $client->onData("* CAPABILITY IMAP4rev1 IMAP4rev2 AUTH=PLAIN\r\n");
        self::assertTrue($client->serverSupportsRev2());
    }

    #[Test]
    public function clientTracksEnabledResponse(): void
    {
        $client = new ClientProtocol();
        $client->on(GreetingEvent::class, function () {});
        $client->on(DataEvent::class, function () {});
        $client->on(TaggedResponseEvent::class, function () {});

        $client->onData("* OK Server ready\r\n");

        // Simulate ENABLED response
        $client->onData("* ENABLED CONDSTORE UTF8=ACCEPT\r\n");

        self::assertTrue($client->session()->isCondstoreEnabled());
        self::assertTrue($client->session()->isUtf8Enabled());
    }

    #[Test]
    public function clientTracksEnabledRev2(): void
    {
        $client = new ClientProtocol();
        $client->on(GreetingEvent::class, function () {});
        $client->on(DataEvent::class, function () {});

        $client->onData("* OK Server ready\r\n");
        $client->onData("* ENABLED IMAP4REV2\r\n");

        self::assertTrue($client->session()->isRev2());
        self::assertTrue($client->session()->useEsearch());
        self::assertFalse($client->session()->supportsRecent());
    }

    #[Test]
    public function clientRev1SessionByDefault(): void
    {
        $client = new ClientProtocol();

        // Before any data, session defaults to rev1
        self::assertTrue($client->session()->isRev1());
        self::assertTrue($client->session()->supportsRecent());
        self::assertFalse($client->session()->useEsearch());
        self::assertTrue($client->session()->useUtf7Mailbox());
    }

    // === FULL FLOW ===

    #[Test]
    public function fullNegotiationFlow(): void
    {
        $server = new ServerProtocol();
        $server->on(LoginEvent::class, fn(LoginEvent $e) => $e->accept());
        $server->on(SimpleEvent::class, fn(SimpleEvent $e) => $e->accept());

        $client = new ClientProtocol();
        $client->on(GreetingEvent::class, function () {});
        $client->on(TaggedResponseEvent::class, function () {});
        $client->on(DataEvent::class, function () {});

        // 1. Greeting
        $client->onData($server->greeting());

        // Both start as rev1
        self::assertTrue($server->session()->isRev1());
        self::assertTrue($client->session()->isRev1());

        // 2. Login
        [$tag, $bytes] = $client->command()->login('user', 'pass');
        foreach ($server->onData($bytes) as $r) {
            $client->onData($r);
        }

        // 3. Enable rev2 + CONDSTORE
        [$tag, $bytes] = $client->command()->enable(['IMAP4REV2', 'CONDSTORE']);
        foreach ($server->onData($bytes) as $r) {
            $client->onData($r);
        }

        // Both should now be rev2 with CONDSTORE
        self::assertTrue($server->session()->isRev2());
        self::assertTrue($server->session()->isCondstoreEnabled());
        self::assertTrue($client->session()->isRev2());
        self::assertTrue($client->session()->isCondstoreEnabled());

        // Protocol behavior should reflect rev2
        self::assertFalse($server->session()->supportsRecent());
        self::assertTrue($server->session()->useEsearch());
        self::assertFalse($server->session()->useUtf7Mailbox());
    }
}
