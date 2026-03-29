<?php
/**
 * Tests for the full server-side IMAP protocol pipeline.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

declare(strict_types=1);

namespace PHPdot\Mail\IMAP\Tests\Unit\Server;

use PHPdot\Mail\IMAP\DataType\Enum\ConnectionState;
use PHPdot\Mail\IMAP\Server\Event\LoginEvent;
use PHPdot\Mail\IMAP\Server\Event\SelectEvent;
use PHPdot\Mail\IMAP\Server\Event\SimpleEvent;
use PHPdot\Mail\IMAP\Server\ServerProtocol;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ServerProtocolTest extends TestCase
{
    #[Test]
    public function greetingFormat(): void
    {
        $server = new ServerProtocol();
        $greeting = $server->greeting();
        self::assertStringStartsWith('* OK', $greeting);
        self::assertStringEndsWith("\r\n", $greeting);
    }

    #[Test]
    public function loginAcceptFlow(): void
    {
        $server = new ServerProtocol();
        $server->on(LoginEvent::class, function (LoginEvent $e): void {
            self::assertSame('user', $e->username());
            self::assertSame('pass', $e->password());
            $e->accept();
        });

        $responses = $server->onData("A001 LOGIN user pass\r\n");
        self::assertCount(1, $responses);
        self::assertStringContainsString('A001 OK', $responses[0]);
        self::assertSame(ConnectionState::Authenticated, $server->state());
    }

    #[Test]
    public function loginRejectFlow(): void
    {
        $server = new ServerProtocol();
        $server->on(LoginEvent::class, function (LoginEvent $e): void {
            $e->reject('Invalid credentials');
        });

        $responses = $server->onData("A001 LOGIN user wrong\r\n");
        self::assertStringContainsString('A001 NO', $responses[0]);
        self::assertSame(ConnectionState::NotAuthenticated, $server->state());
    }

    #[Test]
    public function unhandledCommandReturnsNo(): void
    {
        $server = new ServerProtocol();
        // No handlers registered
        $responses = $server->onData("A001 LOGIN user pass\r\n");
        self::assertStringContainsString('NO', $responses[0]);
    }

    #[Test]
    public function commandInWrongStateReturnsBad(): void
    {
        $server = new ServerProtocol();
        // FETCH requires Selected state, we're in NotAuthenticated
        $responses = $server->onData("A001 FETCH 1 (FLAGS)\r\n");
        self::assertStringContainsString('BAD', $responses[0]);
    }

    #[Test]
    public function logoutSendsByeAndOk(): void
    {
        $server = new ServerProtocol();
        $server->on(SimpleEvent::class, function (SimpleEvent $e): void {
            $e->accept();
        });

        $responses = $server->onData("A001 LOGOUT\r\n");
        self::assertCount(2, $responses);
        self::assertStringContainsString('BYE', $responses[0]);
        self::assertStringContainsString('A001 OK', $responses[1]);
        self::assertTrue($server->isLoggedOut());
    }

    #[Test]
    public function fullSession(): void
    {
        $server = new ServerProtocol();
        $server->on(LoginEvent::class, fn(LoginEvent $e) => $e->accept());
        $server->on(SelectEvent::class, fn(SelectEvent $e) => $e->accept());
        $server->on(SimpleEvent::class, fn(SimpleEvent $e) => $e->accept());

        self::assertSame(ConnectionState::NotAuthenticated, $server->state());

        $server->onData("A001 LOGIN user pass\r\n");
        self::assertSame(ConnectionState::Authenticated, $server->state());

        $server->onData("A002 SELECT INBOX\r\n");
        self::assertSame(ConnectionState::Selected, $server->state());

        $server->onData("A003 CLOSE\r\n");
        self::assertSame(ConnectionState::Authenticated, $server->state());

        $server->onData("A004 LOGOUT\r\n");
        self::assertSame(ConnectionState::Logout, $server->state());
    }

    #[Test]
    public function multipleCommandsInOneChunk(): void
    {
        $server = new ServerProtocol();
        $server->on(SimpleEvent::class, fn(SimpleEvent $e) => $e->accept());

        $responses = $server->onData("A001 NOOP\r\nA002 CAPABILITY\r\n");
        self::assertCount(2, $responses);
    }

    #[Test]
    public function invalidCommandReturnsBadAndCounts(): void
    {
        $server = new ServerProtocol();
        // Send something that can't be parsed as a command
        $responses = $server->onData("NOTACOMMAND\r\n");
        self::assertCount(1, $responses);
        self::assertStringContainsString('BAD', $responses[0]);
    }
}
