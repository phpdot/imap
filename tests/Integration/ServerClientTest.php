<?php
/**
 * Integration test: server and client communicating via in-memory bytes.
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
use PHPdot\Mail\IMAP\DataType\Enum\ConnectionState;
use PHPdot\Mail\IMAP\DataType\Enum\GreetingStatus;
use PHPdot\Mail\IMAP\DataType\Enum\ResponseStatus;
use PHPdot\Mail\IMAP\Server\Event\LoginEvent;
use PHPdot\Mail\IMAP\Server\Event\SelectEvent;
use PHPdot\Mail\IMAP\Server\Event\SimpleEvent;
use PHPdot\Mail\IMAP\Server\ServerProtocol;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Integration test: ServerProtocol and ClientProtocol talking via in-memory byte pipes.
 */
final class ServerClientTest extends TestCase
{
    #[Test]
    public function fullSessionRoundTrip(): void
    {
        // === Setup Server ===
        $server = new ServerProtocol();
        $server->on(LoginEvent::class, function (LoginEvent $e): void {
            if ($e->username() === 'omar' && $e->password() === 'secret') {
                $e->accept(['user_id' => 1]);
            } else {
                $e->reject('Invalid credentials');
            }
        });
        $server->on(SelectEvent::class, function (SelectEvent $e): void {
            $e->accept();
        });
        $server->on(SimpleEvent::class, function (SimpleEvent $e): void {
            $e->accept();
        });

        // === Setup Client ===
        $client = new ClientProtocol();

        /** @var list<string> $log */
        $log = [];

        $client->on(GreetingEvent::class, function (GreetingEvent $e) use (&$log): void {
            $log[] = 'GREETING:' . $e->greeting->status->value;
        });
        $client->on(TaggedResponseEvent::class, function (TaggedResponseEvent $e) use (&$log): void {
            $log[] = 'TAGGED:' . $e->taggedResponse->tag->value . ':' . $e->taggedResponse->status->value;
        });
        $client->on(DataEvent::class, function (DataEvent $e) use (&$log): void {
            $log[] = 'DATA:' . $e->type();
        });

        // === Step 1: Greeting ===
        $greeting = $server->greeting();
        $client->onData($greeting);
        self::assertContains('GREETING:OK', $log);

        // === Step 2: LOGIN (success) ===
        [$loginTag, $loginBytes] = $client->command()->login('omar', 'secret');
        $responses = $server->onData($loginBytes);
        foreach ($responses as $r) {
            $client->onData($r);
        }
        self::assertContains('TAGGED:' . $loginTag->value . ':OK', $log);
        self::assertSame(ConnectionState::Authenticated, $server->state());

        // === Step 3: SELECT INBOX ===
        [$selectTag, $selectBytes] = $client->command()->select('INBOX');
        $responses = $server->onData($selectBytes);
        foreach ($responses as $r) {
            $client->onData($r);
        }
        self::assertContains('TAGGED:' . $selectTag->value . ':OK', $log);
        self::assertSame(ConnectionState::Selected, $server->state());

        // === Step 4: NOOP ===
        [$noopTag, $noopBytes] = $client->command()->noop();
        $responses = $server->onData($noopBytes);
        foreach ($responses as $r) {
            $client->onData($r);
        }
        self::assertContains('TAGGED:' . $noopTag->value . ':OK', $log);

        // === Step 5: CLOSE ===
        [$closeTag, $closeBytes] = $client->command()->close();
        $responses = $server->onData($closeBytes);
        foreach ($responses as $r) {
            $client->onData($r);
        }
        self::assertSame(ConnectionState::Authenticated, $server->state());

        // === Step 6: LOGOUT ===
        [$logoutTag, $logoutBytes] = $client->command()->logout();
        $responses = $server->onData($logoutBytes);
        foreach ($responses as $r) {
            $client->onData($r);
        }
        self::assertContains('DATA:BYE', $log);
        self::assertContains('TAGGED:' . $logoutTag->value . ':OK', $log);
        self::assertTrue($server->isLoggedOut());
    }

    #[Test]
    public function loginFailure(): void
    {
        $server = new ServerProtocol();
        $server->on(LoginEvent::class, function (LoginEvent $e): void {
            $e->reject('Bad password');
        });

        $client = new ClientProtocol();
        $loginResult = null;

        $client->on(GreetingEvent::class, function (): void {});
        $client->on(TaggedResponseEvent::class, function (TaggedResponseEvent $e) use (&$loginResult): void {
            $loginResult = $e->taggedResponse->status;
        });

        $client->onData($server->greeting());

        [$tag, $bytes] = $client->command()->login('user', 'wrong');
        $responses = $server->onData($bytes);
        foreach ($responses as $r) {
            $client->onData($r);
        }

        self::assertSame(ResponseStatus::No, $loginResult);
        self::assertSame(ConnectionState::NotAuthenticated, $server->state());
    }

    #[Test]
    public function multipleCommandsSequentially(): void
    {
        $server = new ServerProtocol();
        $server->on(LoginEvent::class, fn(LoginEvent $e) => $e->accept());
        $server->on(SimpleEvent::class, fn(SimpleEvent $e) => $e->accept());

        $client = new ClientProtocol();
        $completedTags = [];

        $client->on(GreetingEvent::class, function (): void {});
        $client->on(TaggedResponseEvent::class, function (TaggedResponseEvent $e) use (&$completedTags): void {
            $completedTags[] = $e->taggedResponse->tag->value;
        });

        $client->onData($server->greeting());

        // Send LOGIN
        [$t1, $b1] = $client->command()->login('u', 'p');
        foreach ($server->onData($b1) as $r) {
            $client->onData($r);
        }

        // Send CAPABILITY
        [$t2, $b2] = $client->command()->capability();
        foreach ($server->onData($b2) as $r) {
            $client->onData($r);
        }

        // Send NOOP
        [$t3, $b3] = $client->command()->noop();
        foreach ($server->onData($b3) as $r) {
            $client->onData($r);
        }

        self::assertSame([$t1->value, $t2->value, $t3->value], $completedTags);
    }
}
