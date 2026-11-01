<?php

/**
 * IDLE push: a notifier publish must reach the transport writer as an
 * untagged EXISTS — the mechanism that wakes IDLE clients to new mail.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

declare(strict_types=1);

namespace PHPdot\Mail\IMAP\Tests\Integration;

use PHPdot\Mail\IMAP\Connection\InMemoryNotifier;
use PHPdot\Mail\IMAP\Connection\MailboxChange;
use PHPdot\Mail\IMAP\Connection\ServerConnection;
use PHPdot\Mail\IMAP\ImapHandler;
use PHPdot\Mail\IMAP\Result\SelectResult;
use PHPdot\Mail\IMAP\Server\Event\LoginEvent;
use PHPdot\Mail\IMAP\Server\Event\SelectEvent;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class IdlePushTest extends TestCase
{
    #[Test]
    public function publishReachesPushWriterAsUntaggedExists(): void
    {
        $notifier = new InMemoryNotifier();

        $handler = new ImapHandler();
        $handler->onLogin(static function (LoginEvent $event): void {
            $event->accept();
        });
        $handler->onSelect(static function (SelectEvent $event): void {
            $event->accept(new SelectResult(exists: 11));
        });

        $conn = new ServerConnection($handler, $notifier);
        $conn->greeting();
        $conn->onData("a1 LOGIN user pass\r\n");

        $pushed = [];
        $conn->setPushWriter(static function (string $bytes) use (&$pushed): void {
            $pushed[] = $bytes;
        });

        // SELECT subscribes this connection to INBOX changes.
        $conn->onData("a2 SELECT INBOX\r\n");

        // Unsolicited data is pushed to a client that is waiting for it.
        $conn->onData("a3 IDLE\r\n");

        // New mail arrives somewhere else in the process:
        $notifier->publish('INBOX', MailboxChange::exists(12));

        self::assertCount(1, $pushed);
        self::assertSame("* 12 EXISTS\r\n", $pushed[0]);
    }

    #[Test]
    public function publishToOtherMailboxDoesNotPush(): void
    {
        $notifier = new InMemoryNotifier();

        $handler = new ImapHandler();
        $handler->onLogin(static function (LoginEvent $event): void {
            $event->accept();
        });
        $handler->onSelect(static function (SelectEvent $event): void {
            $event->accept(new SelectResult(exists: 2));
        });

        $conn = new ServerConnection($handler, $notifier);
        $conn->greeting();
        $conn->onData("a1 LOGIN user pass\r\n");

        $pushed = [];
        $conn->setPushWriter(static function (string $bytes) use (&$pushed): void {
            $pushed[] = $bytes;
        });
        $conn->onData("a2 SELECT INBOX\r\n");

        $notifier->publish('SENT', MailboxChange::exists(3));

        self::assertSame([], $pushed);
    }

    #[Test]
    public function unsubscribeOnUnselectStopsPushes(): void
    {
        $notifier = new InMemoryNotifier();

        $handler = new ImapHandler();
        $handler->onLogin(static function (LoginEvent $event): void {
            $event->accept();
        });
        $handler->onSelect(static function (SelectEvent $event): void {
            $event->accept(new SelectResult(exists: 5));
        });

        $conn = new ServerConnection($handler, $notifier);
        $conn->greeting();
        $conn->onData("a1 LOGIN user pass\r\n");
        $conn->setPushWriter(static function (string $bytes): void {});
        $conn->onData("a2 SELECT INBOX\r\n");
        $conn->onData("a3 UNSELECT\r\n");

        $pushed = [];
        $conn->setPushWriter(static function (string $bytes) use (&$pushed): void {
            $pushed[] = $bytes;
        });
        $notifier->publish('INBOX', MailboxChange::exists(6));

        self::assertSame([], $pushed);
    }
}
