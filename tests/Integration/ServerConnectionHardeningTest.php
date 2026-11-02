<?php

/**
 * What a hostile or careless client cannot make the server do: run commands out of order, inject lines into
 * responses, read the host's errors, change a read-only mailbox, or hold memory before it has logged in.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

declare(strict_types=1);

namespace PHPdot\Mail\IMAP\Tests\Integration;

use Fiber;
use Generator;
use Iterator;
use PHPdot\Mail\IMAP\Connection\ConnectionContext;
use PHPdot\Mail\IMAP\Connection\InMemoryNotifier;
use PHPdot\Mail\IMAP\Connection\MailboxChange;
use PHPdot\Mail\IMAP\Connection\ServerConnection;
use PHPdot\Mail\IMAP\DataType\DTO\FetchResult;
use PHPdot\Mail\IMAP\DataType\DTO\MailboxInfo;
use PHPdot\Mail\IMAP\DataType\ValueObject\Capability;
use PHPdot\Mail\IMAP\DataType\ValueObject\CapabilitySet;
use PHPdot\Mail\IMAP\DataType\ValueObject\Mailbox;
use PHPdot\Mail\IMAP\ImapHandler;
use PHPdot\Mail\IMAP\Result\SelectResult;
use PHPdot\Mail\IMAP\Server\Event\AuthenticateEvent;
use PHPdot\Mail\IMAP\Server\Event\FetchEvent;
use PHPdot\Mail\IMAP\Server\Event\ListEvent;
use PHPdot\Mail\IMAP\Server\Event\LoginEvent;
use PHPdot\Mail\IMAP\Server\Event\MailboxEvent;
use PHPdot\Mail\IMAP\Server\Event\MetadataEvent;
use PHPdot\Mail\IMAP\Server\Event\SearchEvent;
use PHPdot\Mail\IMAP\Server\Event\SelectEvent;
use PHPdot\Mail\IMAP\Server\Event\StoreEvent;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ServerConnectionHardeningTest extends TestCase
{
    /** @var list<string> */
    private array $created = [];

    private int $stores = 0;

    private function handler(bool $suspendSelect = false): ImapHandler
    {
        $handler = new ImapHandler();
        $handler->onLogin(static function (LoginEvent $event, ConnectionContext $ctx): void {
            $event->accept();
        });
        $handler->onSelect(static function (SelectEvent $event, ConnectionContext $ctx) use ($suspendSelect): void {
            if ($suspendSelect) {
                Fiber::suspend();
            }
            if ($event->mailbox()->name !== 'INBOX') {
                $event->reject('No such mailbox');
                return;
            }
            $event->accept(new SelectResult(exists: 3, uidValidity: 7, uidNext: 4, readWrite: true, uids: [1, 2, 3]));
        });
        $handler->onStore(function (StoreEvent $event, ConnectionContext $ctx): void {
            $this->stores++;
            $event->accept([]);
        });
        $handler->onCreate(function (MailboxEvent $event, ConnectionContext $ctx): void {
            $this->created[] = $event->mailbox()->name;
            $event->accept();
        });

        return $handler;
    }

    private function loggedIn(ImapHandler $handler): ServerConnection
    {
        $conn = new ServerConnection($handler);
        $conn->onData("a LOGIN user pass\r\n");

        return $conn;
    }

    #[Test]
    public function bytesArrivingWhileACommandRunsAreAnsweredAfterIt(): void
    {
        $conn = $this->loggedIn($this->handler(suspendSelect: true));

        $select = new Fiber(static fn(): array => $conn->onData("b SELECT INBOX\r\n"));
        $select->start();
        $second = new Fiber(static fn(): array => $conn->onData("c FETCH 1 FLAGS\r\n"));
        $second->start();
        $select->resume();

        self::assertSame([], $second->getReturn());
        $all = implode('', $select->getReturn());
        self::assertStringContainsString('b OK [READ-WRITE] SELECT completed', $all);
        self::assertGreaterThan(strpos($all, 'b OK'), strpos($all, 'c '));
        self::assertStringNotContainsString('not allowed in', $all);
    }

    #[Test]
    public function aFlagOutsideTheGrammarIsRefusedBeforeTheHostSeesIt(): void
    {
        $conn = $this->loggedIn($this->handler());
        $conn->onData("b SELECT INBOX\r\n");

        $all = implode('', $conn->onData("c STORE 1 +FLAGS ({22+}\r\nx)\r\n* 99 EXPUNGE\r\n* OK)\r\n"));

        self::assertStringNotContainsString('99 EXPUNGE', $all);
        self::assertStringContainsString('BAD', $all);
        self::assertSame(0, $this->stores);
    }

    #[Test]
    public function aHandlersExceptionTextNeverReachesTheClient(): void
    {
        $handler = $this->handler();
        $handler->onCreate(static function (MailboxEvent $event, ConnectionContext $ctx): void {
            throw new RuntimeException("SQLSTATE[23505]: duplicate key\nDETAIL: Key (owner_id)=(42)");
        });

        $all = implode('', $this->loggedIn($handler)->onData("b CREATE Foo\r\n"));

        self::assertSame("b NO Server error\r\n", $all);
    }

    #[Test]
    public function anExaminedMailboxCannotBeChanged(): void
    {
        $conn = $this->loggedIn($this->handler());

        $examine = implode('', $conn->onData("b EXAMINE INBOX\r\n"));
        $store = implode('', $conn->onData("c STORE 1 +FLAGS (\\Deleted)\r\n"));
        $expunge = implode('', $conn->onData("d EXPUNGE\r\n"));

        self::assertStringContainsString('b OK [READ-ONLY]', $examine);
        self::assertSame("c NO Mailbox is read-only\r\n", $store);
        self::assertSame("d NO Mailbox is read-only\r\n", $expunge);
        self::assertSame(0, $this->stores);
    }

    #[Test]
    public function aFailedSelectLeavesTheSelectedState(): void
    {
        $conn = $this->loggedIn($this->handler());
        $conn->onData("b SELECT INBOX\r\n");
        $conn->onData("c SELECT Nowhere\r\n");

        $all = implode('', $conn->onData("d FETCH 1 FLAGS\r\n"));

        self::assertStringStartsWith('d BAD', $all);
        self::assertNull($conn->selectedMailbox());
    }

    #[Test]
    public function aCommandWithoutAHandlerIsRefusedNotAccepted(): void
    {
        $handler = new ImapHandler();
        $handler->onLogin(static function (LoginEvent $event, ConnectionContext $ctx): void {
            $event->accept();
        });
        $handler->onSelect(static function (SelectEvent $event, ConnectionContext $ctx): void {
            $event->accept(new SelectResult(exists: 1, uidValidity: 1, uidNext: 2, readWrite: true, uids: [1]));
        });
        $conn = $this->loggedIn($handler);
        $conn->onData("b SELECT INBOX\r\n");

        self::assertSame("c NO STORE not available\r\n", implode('', $conn->onData("c STORE 1 +FLAGS (\\Seen)\r\n")));
        self::assertSame("d NO EXPUNGE not available\r\n", implode('', $conn->onData("d EXPUNGE\r\n")));
    }

    #[Test]
    public function searchAfterEnablingRev2AnswersWithEsearch(): void
    {
        $handler = $this->handler();
        $handler->onSearch(static function (SearchEvent $event, ConnectionContext $ctx): void {
            $event->accept([1, 2]);
        });
        $conn = $this->loggedIn($handler);
        $conn->onData("b ENABLE IMAP4rev2\r\n");
        $conn->onData("c SELECT INBOX\r\n");

        $all = implode('', $conn->onData("d SEARCH ALL\r\n"));

        self::assertStringContainsString('* ESEARCH (TAG "d") ALL 1:2', $all);
        self::assertStringNotContainsString('* SEARCH', $all);
    }

    #[Test]
    public function mailboxNamesReachTheHostAsUtf8AndLeaveAsModifiedUtf7(): void
    {
        $handler = $this->handler();
        $handler->onList(static function (ListEvent $event, ConnectionContext $ctx): void {
            $event->accept([new MailboxInfo(new Mailbox('Entwürfe'), '/')]);
        });
        $conn = $this->loggedIn($handler);

        $conn->onData("b CREATE Entw&APw-rfe\r\n");
        $list = implode('', $conn->onData("c LIST \"\" *\r\n"));

        self::assertSame(['Entwürfe'], $this->created);
        self::assertStringContainsString('* LIST () "/" Entw&APw-rfe', $list);
    }

    #[Test]
    public function aNonCanonicalModifiedUtf7NameIsRefused(): void
    {
        $conn = $this->loggedIn($this->handler());

        $inbox = implode('', $conn->onData("b CREATE &AEkATgBCAE8AWA-\r\n"));
        $control = implode('', $conn->onData("c CREATE &AAoADQ-\r\n"));
        $surrogate = implode('', $conn->onData("d CREATE &3AA-\r\n"));

        self::assertStringContainsString('BAD', $inbox);
        self::assertStringContainsString('BAD', $control);
        self::assertStringContainsString('BAD', $surrogate);
        self::assertSame([], $this->created);
    }

    #[Test]
    public function tooManyBadCommandsEndTheSession(): void
    {
        $conn = new ServerConnection($this->handler());

        $last = [];
        for ($i = 0; $i < 50; $i++) {
            $last = $conn->onData("a% NOOP\r\n");
        }

        self::assertSame("* BYE Too many invalid commands\r\n", implode('', $last));
        self::assertTrue($conn->isLoggedOut());
        self::assertSame([], $conn->onData("b LOGIN user pass\r\n"));
        self::assertFalse($conn->isAuthenticated());
    }

    #[Test]
    public function aBraceFollowedBySpaceIsNotALiteral(): void
    {
        $conn = new ServerConnection($this->handler());

        $all = implode('', $conn->onData("a LOGIN {5} \r\nalice secret\r\n"));

        self::assertFalse($conn->isAuthenticated());
        self::assertStringNotContainsString('a OK', $all);
    }

    #[Test]
    public function untaggedAndContinuationMarksAreNotTags(): void
    {
        $conn = new ServerConnection($this->handler());

        self::assertStringStartsWith('* BAD', implode('', $conn->onData("* LOGIN user pass\r\n")));
        self::assertStringStartsWith('* BAD', implode('', $conn->onData("+ LOGIN user pass\r\n")));
        self::assertFalse($conn->isAuthenticated());
    }

    #[Test]
    public function literalsBeforeLoginAreCappedPerCommand(): void
    {
        $conn = new ServerConnection($this->handler());

        $all = implode('', $conn->onData("a LOGIN {10000+}\r\n" . str_repeat('x', 10000) . " pass\r\n"));

        self::assertStringContainsString('BAD Literal exceeds the maximum size', $all);
        self::assertFalse($conn->isAuthenticated());
    }

    #[Test]
    public function aStoredMetadataValueIsQuotedNotSpliced(): void
    {
        $handler = $this->handler();
        $handler->onGetMetadata(static function (MetadataEvent $event, ConnectionContext $ctx): void {
            $event->accept(['/shared/comment' => "x\"\r\n* 1 EXPUNGE\r\n* OK \""]);
        });

        $all = implode('', $this->loggedIn($handler)->onData("b GETMETADATA INBOX /shared/comment\r\n"));

        self::assertStringNotContainsString("\r\n* 1 EXPUNGE\r\n", str_replace('{' . strlen("x\"\r\n* 1 EXPUNGE\r\n* OK \"") . "}\r\nx\"\r\n* 1 EXPUNGE\r\n* OK \"", '', $all));
        self::assertStringContainsString('{' . strlen("x\"\r\n* 1 EXPUNGE\r\n* OK \"") . "}\r\n", $all);
    }

    #[Test]
    public function commandsPipelinedAfterStartTlsAreDiscarded(): void
    {
        $conn = new ServerConnection($this->handler());
        $conn->context()->session()->setTlsActive(false);

        $all = implode('', $conn->onData("a STARTTLS\r\nb LOGIN user pass\r\n"));

        self::assertSame("a OK STARTTLS completed\r\n", $all);
        self::assertTrue($conn->needsTlsUpgrade());
        self::assertSame([], $conn->onData("c LOGIN user pass\r\n"));

        $conn->tlsUpgraded();
        self::assertFalse($conn->needsTlsUpgrade());
        self::assertSame("d OK LOGIN completed\r\n", implode('', $conn->onData("d LOGIN user pass\r\n")));
    }

    #[Test]
    public function capabilityWithoutAHandlerListsTheAdvertisedCapabilities(): void
    {
        $conn = new ServerConnection($this->handler());
        $conn->greeting('ready', new CapabilitySet([new Capability('IMAP4rev2'), new Capability('IDLE')]));

        $all = implode('', $conn->onData("a CAPABILITY\r\n"));

        self::assertStringStartsWith('* CAPABILITY IMAP4REV2 IDLE', $all);
    }

    #[Test]
    public function anEmptyInitialResponseIsDecodedAsEmpty(): void
    {
        $seen = null;
        $handler = new ImapHandler();
        $handler->onAuthenticate(static function (AuthenticateEvent $event, ConnectionContext $ctx) use (&$seen): void {
            $seen = $event->response();
            $event->reject('no');
        });

        (new ServerConnection($handler))->onData("a AUTHENTICATE PLAIN =\r\n");

        self::assertSame('', $seen);
    }

    #[Test]
    public function bytesArrivingBehindALazyFetchWaitForResume(): void
    {
        $handler = $this->handler();
        $handler->onFetch(static function (FetchEvent $event, ConnectionContext $ctx): void {
            Fiber::suspend();
            $event->accept((static function (): Generator {
                yield new FetchResult(sequenceNumber: 1, uid: 1);
            })());
        });
        $conn = $this->loggedIn($handler);
        $conn->onData("b SELECT INBOX\r\n");

        $fetch = new Fiber(static fn(): array => $conn->onDataDeferred("c FETCH 1 (UID)\r\n"));
        $fetch->start();
        $noop = new Fiber(static fn(): array => $conn->onDataDeferred("d NOOP\r\n"));
        $noop->start();
        $fetch->resume();

        self::assertSame([], $noop->getReturn());
        $responses = $fetch->getReturn();
        self::assertInstanceOf(Iterator::class, $responses[0]);
        self::assertTrue($conn->hasBacklog());
        foreach ($responses[0] as $chunk) {
            self::assertStringContainsString('UID 1', $chunk);
        }

        self::assertSame("d OK NOOP completed\r\n", implode('', array_filter($conn->resume(), is_string(...))));
        self::assertFalse($conn->hasBacklog());
    }

    #[Test]
    public function aPushedFlagOutsideTheGrammarIsDropped(): void
    {
        $notifier = new InMemoryNotifier();
        $conn = new ServerConnection($this->handler(), $notifier);
        $conn->onData("a LOGIN user pass\r\n");
        $conn->onData("b SELECT INBOX\r\n");

        $notifier->publish('INBOX', MailboxChange::flagChange(2, ['a b', '\\Seen']));
        $all = implode('', $conn->onData("c NOOP\r\n"));

        self::assertStringContainsString("* 2 FETCH (UID 2 FLAGS (\\Seen))\r\n", $all);
        self::assertStringNotContainsString('a b', $all);
    }
}
