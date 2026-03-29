<?php
/**
 * Tests for ServerConnection — verifies the high-level server API generates correct IMAP responses.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

declare(strict_types=1);

namespace PHPdot\Mail\IMAP\Tests\Integration;

use PHPdot\Mail\IMAP\Connection\ConnectionContext;
use PHPdot\Mail\IMAP\Connection\ServerConnection;
use PHPdot\Mail\IMAP\DataType\DTO\FetchResult;
use PHPdot\Mail\IMAP\DataType\DTO\MailboxInfo;
use PHPdot\Mail\IMAP\DataType\DTO\StatusInfo;
use PHPdot\Mail\IMAP\DataType\Enum\MailboxAttribute;
use PHPdot\Mail\IMAP\DataType\ValueObject\CapabilitySet;
use PHPdot\Mail\IMAP\DataType\ValueObject\Flag;
use PHPdot\Mail\IMAP\DataType\ValueObject\ImapDateTime;
use PHPdot\Mail\IMAP\DataType\ValueObject\Mailbox;
use PHPdot\Mail\IMAP\DataType\ValueObject\NamespaceSet;
use PHPdot\Mail\IMAP\DataType\ValueObject\Namespace_;
use PHPdot\Mail\IMAP\ImapHandler;
use PHPdot\Mail\IMAP\Result\SelectResult;
use PHPdot\Mail\IMAP\Server\Event\FetchEvent;
use PHPdot\Mail\IMAP\Server\Event\ListEvent;
use PHPdot\Mail\IMAP\Server\Event\LoginEvent;
use PHPdot\Mail\IMAP\Server\Event\SearchEvent;
use PHPdot\Mail\IMAP\Server\Event\SelectEvent;
use PHPdot\Mail\IMAP\Server\Event\SimpleEvent;
use PHPdot\Mail\IMAP\Server\Event\StatusEvent;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ServerConnectionTest extends TestCase
{
    private function createServer(ImapHandler $handler): ServerConnection
    {
        return new ServerConnection($handler);
    }

    private function loginAndGetServer(ImapHandler $handler): ServerConnection
    {
        $conn = $this->createServer($handler);
        $conn->onData("A001 LOGIN user pass\r\n");
        return $conn;
    }

    #[Test]
    public function selectSendsExistsFlagsUidValidity(): void
    {
        $handler = new ImapHandler();
        $handler->onLogin(function (LoginEvent $event, ConnectionContext $ctx): void {
            $event->accept();
        });
        $handler->onSelect(function (SelectEvent $event, ConnectionContext $ctx): void {
            $event->accept(new SelectResult(
                exists: 172,
                recent: 3,
                flags: [new Flag('\\Answered'), new Flag('\\Seen'), new Flag('\\Deleted')],
                permanentFlags: [new Flag('\\Answered'), new Flag('\\Seen')],
                uidValidity: 3857529045,
                uidNext: 4392,
                highestModseq: 12345,
                readWrite: true,
            ));
        });

        $conn = $this->loginAndGetServer($handler);
        $responses = $conn->onData("A002 SELECT INBOX\r\n");
        $all = implode('', $responses);

        self::assertStringContainsString('* 172 EXISTS', $all);
        self::assertStringContainsString('* 3 RECENT', $all);
        self::assertStringContainsString('\\Answered', $all);
        self::assertStringContainsString('\\Seen', $all);
        self::assertStringContainsString('UIDVALIDITY 3857529045', $all);
        self::assertStringContainsString('UIDNEXT 4392', $all);
        self::assertStringContainsString('HIGHESTMODSEQ 12345', $all);
        self::assertStringContainsString('A002 OK', $all);
    }

    #[Test]
    public function fetchSendsFetchResponses(): void
    {
        $handler = new ImapHandler();
        $handler->onLogin(function (LoginEvent $event, ConnectionContext $ctx): void {
            $event->accept();
        });
        $handler->onSelect(function (SelectEvent $event, ConnectionContext $ctx): void {
            $event->accept(new SelectResult(exists: 5));
        });
        $handler->onFetch(function (FetchEvent $event, ConnectionContext $ctx): void {
            $event->accept([
                new FetchResult(sequenceNumber: 1, uid: 101, flags: [new Flag('\\Seen')], rfc822Size: 4423),
                new FetchResult(sequenceNumber: 2, uid: 102, flags: [], rfc822Size: 1205),
            ]);
        });

        $conn = $this->loginAndGetServer($handler);
        $conn->onData("A002 SELECT INBOX\r\n");
        $responses = $conn->onData("A003 FETCH 1:2 (UID FLAGS RFC822.SIZE)\r\n");
        $all = implode('', $responses);

        self::assertStringContainsString('* 1 FETCH', $all);
        self::assertStringContainsString('UID 101', $all);
        self::assertStringContainsString('* 2 FETCH', $all);
        self::assertStringContainsString('UID 102', $all);
        self::assertStringContainsString('A003 OK', $all);
    }

    #[Test]
    public function searchSendsSearchResponse(): void
    {
        $handler = new ImapHandler();
        $handler->onLogin(function (LoginEvent $event, ConnectionContext $ctx): void {
            $event->accept();
        });
        $handler->onSelect(function (SelectEvent $event, ConnectionContext $ctx): void {
            $event->accept(new SelectResult(exists: 10));
        });
        $handler->onSearch(function (SearchEvent $event, ConnectionContext $ctx): void {
            $event->accept([2, 5, 9]);
        });

        $conn = $this->loginAndGetServer($handler);
        $conn->onData("A002 SELECT INBOX\r\n");
        $responses = $conn->onData("A003 SEARCH UNSEEN\r\n");
        $all = implode('', $responses);

        self::assertStringContainsString('* SEARCH 2 5 9', $all);
        self::assertStringContainsString('A003 OK', $all);
    }

    #[Test]
    public function listSendsListResponses(): void
    {
        $handler = new ImapHandler();
        $handler->onLogin(function (LoginEvent $event, ConnectionContext $ctx): void {
            $event->accept();
        });
        $handler->onList(function (ListEvent $event, ConnectionContext $ctx): void {
            $event->accept([
                new MailboxInfo(new Mailbox('INBOX'), '/', [MailboxAttribute::HasNoChildren]),
                new MailboxInfo(new Mailbox('Sent'), '/', [MailboxAttribute::HasNoChildren, MailboxAttribute::Sent]),
                new MailboxInfo(new Mailbox('Trash'), '/', [MailboxAttribute::HasNoChildren, MailboxAttribute::Trash]),
            ]);
        });

        $conn = $this->loginAndGetServer($handler);
        $responses = $conn->onData("A002 LIST \"\" \"*\"\r\n");
        $all = implode('', $responses);

        self::assertStringContainsString('* LIST', $all);
        self::assertStringContainsString('INBOX', $all);
        self::assertStringContainsString('Sent', $all);
        self::assertStringContainsString('Trash', $all);
        self::assertStringContainsString('A002 OK', $all);
    }

    #[Test]
    public function statusSendsStatusResponse(): void
    {
        $handler = new ImapHandler();
        $handler->onLogin(function (LoginEvent $event, ConnectionContext $ctx): void {
            $event->accept();
        });
        $handler->onStatus(function (StatusEvent $event, ConnectionContext $ctx): void {
            $event->accept(new StatusInfo(
                mailbox: new Mailbox('INBOX'),
                messages: 172,
                unseen: 12,
                uidNext: 4392,
                uidValidity: 3857529045,
            ));
        });

        $conn = $this->loginAndGetServer($handler);
        $responses = $conn->onData("A002 STATUS INBOX (MESSAGES UNSEEN)\r\n");
        $all = implode('', $responses);

        self::assertStringContainsString('* STATUS', $all);
        self::assertStringContainsString('MESSAGES 172', $all);
        self::assertStringContainsString('UNSEEN 12', $all);
        self::assertStringContainsString('A002 OK', $all);
    }

    #[Test]
    public function capabilitySendsCapabilityResponse(): void
    {
        $handler = new ImapHandler();
        $handler->onCapability(function (SimpleEvent $event, ConnectionContext $ctx): void {
            $event->accept(CapabilitySet::fromArray([
                'IMAP4rev1', 'IMAP4rev2', 'AUTH=PLAIN', 'IDLE', 'MOVE',
            ]));
        });

        $conn = $this->createServer($handler);
        $responses = $conn->onData("A001 CAPABILITY\r\n");
        $all = implode('', $responses);

        self::assertStringContainsString('* CAPABILITY', $all);
        self::assertStringContainsString('IMAP4REV2', $all);
        self::assertStringContainsString('IDLE', $all);
        self::assertStringContainsString('A001 OK', $all);
    }

    #[Test]
    public function namespaceSendsNamespaceResponse(): void
    {
        $handler = new ImapHandler();
        $handler->onLogin(function (LoginEvent $event, ConnectionContext $ctx): void {
            $event->accept();
        });
        $handler->onNamespace(function (SimpleEvent $event, ConnectionContext $ctx): void {
            $event->accept(new NamespaceSet(
                personal: [new Namespace_('', '/')],
            ));
        });

        $conn = $this->loginAndGetServer($handler);
        $responses = $conn->onData("A002 NAMESPACE\r\n");
        $all = implode('', $responses);

        self::assertStringContainsString('* NAMESPACE', $all);
        self::assertStringContainsString('A002 OK', $all);
    }

    #[Test]
    public function expungeSendsExpungeResponses(): void
    {
        $handler = new ImapHandler();
        $handler->onLogin(function (LoginEvent $event, ConnectionContext $ctx): void {
            $event->accept();
        });
        $handler->onSelect(function (SelectEvent $event, ConnectionContext $ctx): void {
            $event->accept(new SelectResult(exists: 10));
        });
        $handler->onExpunge(function (SimpleEvent $event, ConnectionContext $ctx): void {
            $event->accept([3, 5, 7]);
        });

        $conn = $this->loginAndGetServer($handler);
        $conn->onData("A002 SELECT INBOX\r\n");
        $responses = $conn->onData("A003 EXPUNGE\r\n");
        $all = implode('', $responses);

        self::assertStringContainsString('* 3 EXPUNGE', $all);
        self::assertStringContainsString('* 5 EXPUNGE', $all);
        self::assertStringContainsString('* 7 EXPUNGE', $all);
        self::assertStringContainsString('A003 OK', $all);
    }

    #[Test]
    public function loginRejectedReturnsNo(): void
    {
        $handler = new ImapHandler();
        $handler->onLogin(function (LoginEvent $event, ConnectionContext $ctx): void {
            $event->reject('Invalid credentials');
        });

        $conn = $this->createServer($handler);
        $responses = $conn->onData("A001 LOGIN user wrong\r\n");
        $all = implode('', $responses);

        self::assertStringContainsString('A001 NO', $all);
        self::assertFalse($conn->isAuthenticated());
    }

    #[Test]
    public function pushNotifications(): void
    {
        $handler = new ImapHandler();
        $conn = $this->createServer($handler);

        $exists = $conn->pushExists(173);
        self::assertStringContainsString('* 173 EXISTS', $exists);

        $expunge = $conn->pushExpunge(5);
        self::assertStringContainsString('* 5 EXPUNGE', $expunge);

        $flags = $conn->pushFlagUpdate(3, 1055, ['\\Seen', '\\Flagged']);
        self::assertStringContainsString('* 3 FETCH', $flags);
        self::assertStringContainsString('UID 1055', $flags);
    }

    #[Test]
    public function fullSessionFlow(): void
    {
        $handler = new ImapHandler();
        $handler->onLogin(function (LoginEvent $event, ConnectionContext $ctx): void {
            $event->accept();
        });
        $handler->onSelect(function (SelectEvent $event, ConnectionContext $ctx): void {
            $event->accept(new SelectResult(exists: 5, uidValidity: 100, uidNext: 50));
        });
        $handler->onFetch(function (FetchEvent $event, ConnectionContext $ctx): void {
            $event->accept([
                new FetchResult(sequenceNumber: 1, uid: 10, flags: [new Flag('\\Seen')]),
            ]);
        });
        $handler->onSearch(function (SearchEvent $event, ConnectionContext $ctx): void {
            $event->accept([1, 3]);
        });

        $conn = $this->createServer($handler);

        // Greeting
        $greeting = $conn->greeting();
        self::assertStringContainsString('* OK', $greeting);

        // Login
        $r = $conn->onData("A001 LOGIN user pass\r\n");
        self::assertTrue($conn->isAuthenticated());

        // Select
        $r = $conn->onData("A002 SELECT INBOX\r\n");
        $all = implode('', $r);
        self::assertStringContainsString('* 5 EXISTS', $all);
        self::assertSame('INBOX', $conn->selectedMailbox());

        // Fetch
        $r = $conn->onData("A003 FETCH 1 (UID FLAGS)\r\n");
        $all = implode('', $r);
        self::assertStringContainsString('* 1 FETCH', $all);
        self::assertStringContainsString('UID 10', $all);

        // Search
        $r = $conn->onData("A004 SEARCH UNSEEN\r\n");
        $all = implode('', $r);
        self::assertStringContainsString('* SEARCH 1 3', $all);

        // Logout
        $r = $conn->onData("A005 LOGOUT\r\n");
        $all = implode('', $r);
        self::assertStringContainsString('BYE', $all);
    }
}
