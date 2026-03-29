<?php
/**
 * Tests for the IMAP server event emitter.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

declare(strict_types=1);

namespace PHPdot\Mail\IMAP\Tests\Unit\Server;

use PHPdot\Mail\IMAP\DataType\Enum\ResponseCode;
use PHPdot\Mail\IMAP\DataType\Enum\ResponseStatus;
use PHPdot\Mail\IMAP\DataType\ValueObject\Tag;
use PHPdot\Mail\IMAP\Exception\RuntimeException;
use PHPdot\Mail\IMAP\Server\Command\LoginCommand;
use PHPdot\Mail\IMAP\Server\Command\SimpleCommand;
use PHPdot\Mail\IMAP\Server\Event\Event;
use PHPdot\Mail\IMAP\Server\Event\LoginEvent;
use PHPdot\Mail\IMAP\Server\Event\SimpleEvent;
use PHPdot\Mail\IMAP\Server\EventEmitter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EventEmitterTest extends TestCase
{
    #[Test]
    public function emitsToRegisteredHandler(): void
    {
        $emitter = new EventEmitter();
        $called = false;

        $emitter->on(LoginEvent::class, function (LoginEvent $e) use (&$called): void {
            $called = true;
            $e->accept();
        });

        $event = new LoginEvent(new LoginCommand(new Tag('A001'), 'user', 'pass'));
        $emitter->emit($event);

        self::assertTrue($called);
        self::assertTrue($event->isHandled());
        self::assertTrue($event->isAccepted());
    }

    #[Test]
    public function eventAcceptCarriesData(): void
    {
        $event = new LoginEvent(new LoginCommand(new Tag('A001'), 'user', 'pass'));
        $event->accept(['user_id' => 42]);

        self::assertSame(['user_id' => 42], $event->getResult());
    }

    #[Test]
    public function eventRejectCarriesDetails(): void
    {
        $event = new LoginEvent(new LoginCommand(new Tag('A001'), 'user', 'pass'));
        $event->reject('Bad password', ResponseStatus::No, ResponseCode::AuthenticationFailed);

        self::assertTrue($event->isHandled());
        self::assertFalse($event->isAccepted());
        self::assertSame(ResponseStatus::No, $event->getRejectStatus());
        self::assertSame(ResponseCode::AuthenticationFailed, $event->getRejectCode());
        self::assertSame('Bad password', $event->getRejectText());
    }

    #[Test]
    public function doubleAcceptThrows(): void
    {
        $event = new SimpleEvent(new SimpleCommand(new Tag('A001'), 'NOOP'));
        $event->accept();

        $this->expectException(RuntimeException::class);
        $event->accept();
    }

    #[Test]
    public function doubleRejectThrows(): void
    {
        $event = new SimpleEvent(new SimpleCommand(new Tag('A001'), 'NOOP'));
        $event->reject('fail');

        $this->expectException(RuntimeException::class);
        $event->reject('fail again');
    }

    #[Test]
    public function acceptThenRejectThrows(): void
    {
        $event = new SimpleEvent(new SimpleCommand(new Tag('A001'), 'NOOP'));
        $event->accept();

        $this->expectException(RuntimeException::class);
        $event->reject('too late');
    }

    #[Test]
    public function firstHandlerWins(): void
    {
        $emitter = new EventEmitter();
        $callOrder = [];

        $emitter->on(SimpleEvent::class, function (SimpleEvent $e) use (&$callOrder): void {
            $callOrder[] = 'first';
            $e->accept('from first');
        });

        $emitter->on(SimpleEvent::class, function (SimpleEvent $e) use (&$callOrder): void {
            $callOrder[] = 'second';
            $e->accept('from second');
        });

        $event = new SimpleEvent(new SimpleCommand(new Tag('A001'), 'NOOP'));
        $emitter->emit($event);

        self::assertSame(['first'], $callOrder);
        self::assertSame('from first', $event->getResult());
    }

    #[Test]
    public function unhandledEventIsNotHandled(): void
    {
        $emitter = new EventEmitter();
        $event = new SimpleEvent(new SimpleCommand(new Tag('A001'), 'NOOP'));
        $emitter->emit($event);

        self::assertFalse($event->isHandled());
    }

    #[Test]
    public function offRemovesHandlers(): void
    {
        $emitter = new EventEmitter();
        $called = false;

        $emitter->on(SimpleEvent::class, function () use (&$called): void {
            $called = true;
        });

        $emitter->off(SimpleEvent::class);

        $event = new SimpleEvent(new SimpleCommand(new Tag('A001'), 'NOOP'));
        $emitter->emit($event);

        self::assertFalse($called);
    }

    #[Test]
    public function removeAllClearsEverything(): void
    {
        $emitter = new EventEmitter();
        $emitter->on(SimpleEvent::class, function (SimpleEvent $e): void {
            $e->accept();
        });
        $emitter->on(LoginEvent::class, function (LoginEvent $e): void {
            $e->accept();
        });

        $emitter->removeAll();

        $event = new SimpleEvent(new SimpleCommand(new Tag('A001'), 'NOOP'));
        $emitter->emit($event);
        self::assertFalse($event->isHandled());
    }
}
