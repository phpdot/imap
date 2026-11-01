<?php

/**
 * Per-session mailbox view: sequence maps, queued cross-session changes,
 * command framing and identity.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

declare(strict_types=1);

namespace PHPdot\Mail\IMAP\Tests\Integration;

use PHPdot\Mail\IMAP\Auth\SaslToken;
use PHPdot\Mail\IMAP\Connection\ConnectionContext;
use PHPdot\Mail\IMAP\Connection\InMemoryNotifier;
use PHPdot\Mail\IMAP\Connection\MailboxChange;
use PHPdot\Mail\IMAP\Connection\ServerConnection;
use PHPdot\Mail\IMAP\DataType\DTO\FetchItem;
use PHPdot\Mail\IMAP\DataType\DTO\FetchResult;
use PHPdot\Mail\IMAP\DataType\DTO\MailboxInfo;
use PHPdot\Mail\IMAP\DataType\DTO\SearchQuery;
use PHPdot\Mail\IMAP\DataType\Enum\SearchKey;
use PHPdot\Mail\IMAP\DataType\ValueObject\CapabilitySet;
use PHPdot\Mail\IMAP\DataType\ValueObject\Mailbox;
use PHPdot\Mail\IMAP\DataType\ValueObject\SequenceSet;
use PHPdot\Mail\IMAP\ImapHandler;
use PHPdot\Mail\IMAP\Protocol\Search\QueryMatcher;
use PHPdot\Mail\IMAP\Protocol\WireFormat;
use PHPdot\Mail\IMAP\Result\SelectResult;
use PHPdot\Mail\IMAP\Server\Event\AuthenticateEvent;
use PHPdot\Mail\IMAP\Server\Event\FetchEvent;
use PHPdot\Mail\IMAP\Server\Event\ListEvent;
use PHPdot\Mail\IMAP\Server\Event\LoginEvent;
use PHPdot\Mail\IMAP\Server\Event\SearchEvent;
use PHPdot\Mail\IMAP\Server\Event\SelectEvent;
use PHPdot\Mail\IMAP\Server\Event\SimpleEvent;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SessionViewTest extends TestCase
{
    /** @var list<string> */
    private array $pushed = [];

    /**
     * @param list<int> $uids
     */
    private function selected(InMemoryNotifier $notifier, array $uids, ImapHandler|null $handler = null): ServerConnection
    {
        $handler ??= new ImapHandler();
        $handler->onLogin(static function (LoginEvent $event): void {
            $event->accept();
        });
        $handler->onSelect(static function (SelectEvent $event) use ($uids): void {
            $event->accept(new SelectResult(exists: count($uids), readWrite: !$event->isReadOnly(), uids: $uids));
        });

        $conn = new ServerConnection($handler, $notifier);
        $this->pushed = [];
        $conn->setPushWriter(function (string $bytes): void {
            $this->pushed[] = $bytes;
        });
        $conn->onData("a1 LOGIN user pass\r\n");
        $conn->onData("a2 SELECT INBOX\r\n");

        return $conn;
    }

    #[Test]
    public function changesAreHeldUntilTheNextCommandWhenNotIdling(): void
    {
        $notifier = new InMemoryNotifier();
        $conn = $this->selected($notifier, [10, 11, 12]);

        $notifier->publish('INBOX', MailboxChange::expunge(11));
        $notifier->publish('INBOX', MailboxChange::added(13));

        self::assertSame([], $this->pushed, 'nothing may be sent while no command is in progress');

        $out = implode('', $conn->onData("a3 NOOP\r\n"));

        self::assertSame("* 2 EXPUNGE\r\n* 3 EXISTS\r\na3 OK NOOP completed\r\n", $out);
        self::assertSame([10, 12, 13], $conn->context()->uidList());
    }

    #[Test]
    public function sequenceNumbersStayStableAcrossFetchStoreSearch(): void
    {
        $notifier = new InMemoryNotifier();
        $handler = new ImapHandler();
        $handler->onFetch(static function (FetchEvent $event, ConnectionContext $ctx): void {
            // The host resolves sequence numbers through the session's own map.
            $event->accept([new FetchResult(sequenceNumber: 3, uid: $ctx->sequenceToUid(3))]);
        });
        $conn = $this->selected($notifier, [10, 11, 12], $handler);

        $notifier->publish('INBOX', MailboxChange::expunge(10));

        // A sequence-number FETCH must not be renumbered underneath the client.
        $out = implode('', $conn->onData("a3 FETCH 3 (UID)\r\n"));
        self::assertSame("* 3 FETCH (UID 12)\r\na3 OK FETCH completed\r\n", $out);

        // The UID form may carry the EXPUNGE.
        $out = implode('', $conn->onData("a4 UID FETCH 12 (UID)\r\n"));
        self::assertStringContainsString("* 1 EXPUNGE\r\na4 OK", $out);
        self::assertSame([11, 12], $conn->context()->uidList());
    }

    #[Test]
    public function idlingClientReceivesChangesImmediatelyInItsOwnNumbering(): void
    {
        $notifier = new InMemoryNotifier();
        $conn = $this->selected($notifier, [10, 11, 12]);
        $conn->onData("a3 IDLE\r\n");

        $notifier->publish('INBOX', MailboxChange::flagChange(12, ['\\Seen']));
        $notifier->publish('INBOX', MailboxChange::expunge(10));
        $notifier->publish('INBOX', MailboxChange::expunge(12));
        $notifier->publish('INBOX', MailboxChange::expunge(99)); // unknown to this session

        self::assertSame(
            ["* 3 FETCH (UID 12 FLAGS (\\Seen))\r\n", "* 1 EXPUNGE\r\n", "* 2 EXPUNGE\r\n"],
            $this->pushed,
        );
    }

    #[Test]
    public function ownExpungesAreReportedHighestFirstAndPruned(): void
    {
        $notifier = new InMemoryNotifier();
        $handler = new ImapHandler();
        $handler->onExpunge(static function (SimpleEvent $event): void {
            $event->accept([1, 3]); // pre-expunge numbering
        });
        $conn = $this->selected($notifier, [10, 11, 12], $handler);

        $out = implode('', $conn->onData("a3 EXPUNGE\r\n"));

        self::assertSame("* 3 EXPUNGE\r\n* 1 EXPUNGE\r\na3 OK EXPUNGE completed\r\n", $out);
        self::assertSame([11], $conn->context()->uidList());
    }

    #[Test]
    public function pipelinedCommandsKeepTheirOwnUntaggedData(): void
    {
        $notifier = new InMemoryNotifier();
        $handler = new ImapHandler();
        $handler->onSearch(static function (SearchEvent $event): void {
            $event->accept($event->isUid() ? [10, 11] : [1, 2]);
        });
        $conn = $this->selected($notifier, [10, 11], $handler);

        $out = implode('', $conn->onData("a3 SEARCH ALL\r\na4 UID SEARCH ALL\r\n"));

        self::assertSame(
            "* SEARCH 1 2\r\na3 OK SEARCH completed\r\n* SEARCH 10 11\r\na4 OK UID SEARCH completed\r\n",
            $out,
        );
    }

    #[Test]
    public function reselectDropsQueuedChangesOfThePreviousMailbox(): void
    {
        $notifier = new InMemoryNotifier();
        $conn = $this->selected($notifier, [10, 11]);
        $notifier->publish('INBOX', MailboxChange::expunge(10));

        $out = implode('', $conn->onData("a3 SELECT INBOX\r\n"));

        self::assertStringNotContainsString('EXPUNGE', $out);
        self::assertSame([10, 11], $conn->context()->uidList());
    }

    #[Test]
    public function examineIsReportedAsExamineAndReadOnly(): void
    {
        $conn = $this->selected(new InMemoryNotifier(), [1]);
        $out = implode('', $conn->onData("a3 EXAMINE INBOX\r\n"));

        self::assertStringContainsString("a3 OK [READ-ONLY] EXAMINE completed\r\n", $out);
    }

    #[Test]
    public function capabilityIsSentOnceAndLsubAnswersWithLsub(): void
    {
        $handler = new ImapHandler();
        $handler->onCapability(static function (SimpleEvent $event): void {
            $event->accept(CapabilitySet::fromArray(['IMAP4rev1', 'IDLE']));
        });
        $handler->onList(static function (ListEvent $event): void {
            $event->accept([new MailboxInfo(new Mailbox('INBOX'), '/')]);
        });
        $conn = $this->selected(new InMemoryNotifier(), [], $handler);

        $out = implode('', $conn->onData("a3 CAPABILITY\r\n"));
        self::assertSame(1, substr_count($out, '* CAPABILITY'));

        self::assertStringContainsString('* LSUB () "/" INBOX', implode('', $conn->onData("a4 LSUB \"\" *\r\n")));
        self::assertStringContainsString('* LIST () "/" INBOX', implode('', $conn->onData("a5 LIST \"\" *\r\n")));
    }

    #[Test]
    public function authenticatePlainExposesCredentialsWithAndWithoutInitialResponse(): void
    {
        $seen = [];
        $handler = new ImapHandler();
        $handler->onAuthenticate(static function (AuthenticateEvent $event) use (&$seen): void {
            $seen[] = $event->plainCredentials();
            $event->accept();
        });

        $token = SaslToken::plain('omar@example.org', 's3cret');

        $withIr = new ServerConnection($handler);
        $withIr->onData("a1 AUTHENTICATE PLAIN {$token}\r\n");

        $challenged = new ServerConnection($handler);
        self::assertSame(["+ \r\n"], $challenged->onData("a1 AUTHENTICATE PLAIN\r\n"));
        $challenged->onData($token . "\r\n");

        self::assertSame('omar@example.org', $seen[0]['username'] ?? null);
        self::assertSame('s3cret', $seen[1]['password'] ?? null);
        self::assertSame('omar@example.org', $withIr->context()->user());
        self::assertSame('omar@example.org', $challenged->context()->user());
    }

    #[Test]
    public function fetchItemsCarrySectionsAndPartials(): void
    {
        $captured = [];
        $handler = new ImapHandler();
        $handler->onFetch(static function (FetchEvent $event) use (&$captured): void {
            $captured = $event->fetchItems();
            $event->accept([]);
        });
        $conn = $this->selected(new InMemoryNotifier(), [1], $handler);
        $conn->onData("a3 FETCH 1 (FLAGS BODY BODY.PEEK[HEADER.FIELDS (From To)]<0.512> BODY[1.2] RFC822.SIZE)\r\n");

        self::assertEquals([
            new FetchItem('FLAGS'),
            new FetchItem('BODY'),
            new FetchItem('BODY.PEEK', 'HEADER.FIELDS (From To)', 0, 512),
            new FetchItem('BODY', '1.2'),
            new FetchItem('RFC822.SIZE'),
        ], $captured);

        self::assertTrue($captured[1]->isPeek());
        self::assertTrue($captured[2]->isPeek());
        self::assertFalse($captured[3]->isPeek());
        self::assertTrue($captured[2]->isPartial());
    }

    #[Test]
    public function fetchResponseEmitsPartialOriginAndRfc822Items(): void
    {
        $wire = WireFormat::fetchResponse(new FetchResult(
            sequenceNumber: 4,
            bodySections: ['TEXT' => 'abc', '1' => null],
            sectionOrigins: ['TEXT' => 10],
            rfc822Header: "A: b\r\n\r\n",
        ));

        self::assertSame(
            "* 4 FETCH (BODY[TEXT]<10> {3}\r\nabc BODY[1] NIL RFC822.HEADER {8}\r\nA: b\r\n\r\n)\r\n",
            $wire,
        );
    }

    #[Test]
    public function bareSequenceSetIsNotAUidSet(): void
    {
        $queries = [];
        $handler = new ImapHandler();
        $handler->onSearch(static function (SearchEvent $event) use (&$queries): void {
            $queries = $event->queries();
            $event->accept([]);
        });
        $conn = $this->selected(new InMemoryNotifier(), [1], $handler);
        $conn->onData("a3 UID SEARCH 2:3 UID 100:* (SEEN FLAGGED)\r\n");

        self::assertSame(SearchKey::SequenceSet, $queries[0]->key);
        self::assertSame(SearchKey::Uid, $queries[1]->key);

        $matcher = new QueryMatcher();
        $message = [
            'flags' => ['\\Seen'],
            'internalDate' => new \DateTimeImmutable('2026-10-01 23:30:00 +0300'),
            'headerDate' => null,
            'size' => 10,
            'headers' => [],
            'bodyText' => '',
            'uid' => 150,
            'sequenceNumber' => 2,
        ];

        self::assertTrue($matcher->matches($queries[0], $message, 5, 200));
        self::assertTrue($matcher->matches($queries[1], $message, 5, 200));
        self::assertFalse($matcher->matches(new SearchQuery(SearchKey::SequenceSet, '4:*'), $message, 5, 200));
        self::assertFalse($matcher->matches(new SearchQuery(SearchKey::Uid, '1:99'), $message, 5, 200));
        // Parenthesized group is an AND of its children, not "everything".
        self::assertFalse($matcher->matches($queries[2], $message, 5, 200));
        // Dates compare by calendar day in the message's own timezone.
        self::assertTrue($matcher->matches(new SearchQuery(SearchKey::On, '1-Oct-2026'), $message));
        self::assertFalse($matcher->matches(new SearchQuery(SearchKey::Since, '2-Oct-2026'), $message));
    }

    #[Test]
    public function enableOnlyTurnsOnWhatTheServerAdvertises(): void
    {
        $handler = new ImapHandler();
        $handler->onLogin(static function (LoginEvent $event): void {
            $event->accept(CapabilitySet::fromArray(['IMAP4rev1', 'ENABLE', 'UTF8=ACCEPT']));
        });
        $handler->onSelect(static function (SelectEvent $event): void {
            $event->accept(new SelectResult(exists: 0, uids: []));
        });

        $conn = new ServerConnection($handler);
        $conn->onData("a1 LOGIN user pass\r\n");
        $out = implode('', $conn->onData("a2 ENABLE IMAP4rev2 CONDSTORE UTF8=ACCEPT\r\n"));

        self::assertStringContainsString("* ENABLED UTF8=ACCEPT\r\n", $out);
        self::assertFalse($conn->context()->session()->isRev2());
        // Still IMAP4rev1, so SELECT still reports RECENT.
        self::assertStringContainsString('* 0 RECENT', implode('', $conn->onData("a3 SELECT INBOX\r\n")));
    }

    #[Test]
    public function changesStayWithinTheirChannelScope(): void
    {
        $notifier = new InMemoryNotifier();
        $handler = new ImapHandler();
        $handler->onLogin(static function (LoginEvent $event, ConnectionContext $ctx): void {
            $ctx->setChannelScope($event->username());
            $event->accept();
        });
        $handler->onSelect(static function (SelectEvent $event): void {
            $event->accept(new SelectResult(exists: 1, uids: [5]));
        });

        $alice = new ServerConnection($handler, $notifier);
        $alice->onData("a1 LOGIN alice pass\r\n");
        $alice->onData("a2 SELECT INBOX\r\n");

        $notifier->publish(ConnectionContext::channel('bob', 'INBOX'), MailboxChange::added(6));
        self::assertSame("a3 OK NOOP completed\r\n", implode('', $alice->onData("a3 NOOP\r\n")));

        $notifier->publish(ConnectionContext::channel('alice', 'INBOX'), MailboxChange::added(6));
        self::assertStringContainsString('* 2 EXISTS', implode('', $alice->onData("a4 NOOP\r\n")));
    }

    #[Test]
    public function lazyFetchResultsAreProducedOnDemandAndHoldBackPipelinedCommands(): void
    {
        $produced = 0;
        $log = new \ArrayObject();
        $handler = new ImapHandler();
        $handler->onFetch(static function (FetchEvent $event) use (&$produced): void {
            $event->accept((static function () use (&$produced): \Generator {
                foreach ([1, 2, 3] as $seq) {
                    $produced++;
                    yield new FetchResult(sequenceNumber: $seq, uid: 10 + $seq);
                }
            })());
        });
        $handler->onExpunge(static function (SimpleEvent $event) use ($log): void {
            $log->append('expunge');
            $event->accept([]);
        });
        $conn = $this->selected(new InMemoryNotifier(), [11, 12, 13], $handler);

        $responses = $conn->onDataDeferred("a3 FETCH 1:3 (UID)\r\na4 EXPUNGE\r\n");

        self::assertCount(2, $responses);
        self::assertInstanceOf(\Iterator::class, $responses[0]);
        self::assertSame("a3 OK FETCH completed\r\n", $responses[1]);
        self::assertSame(0, $produced, 'nothing is read until the transport asks');
        self::assertCount(0, $log, 'the pipelined EXPUNGE waits for the FETCH to be delivered');
        self::assertTrue($conn->hasBacklog());

        self::assertSame("* 1 FETCH (UID 11)\r\n", $responses[0]->current());
        self::assertSame(1, $produced);
        self::assertCount(3, iterator_to_array($responses[0], false));

        self::assertSame(["a4 OK EXPUNGE completed\r\n"], $conn->resume());
        self::assertCount(1, $log);

        // The eager API yields the same bytes, in order.
        $eager = implode('', $conn->onData("a5 FETCH 1:3 (UID)\r\na6 EXPUNGE\r\n"));
        self::assertStringEndsWith("* 3 FETCH (UID 13)\r\na5 OK FETCH completed\r\na6 OK EXPUNGE completed\r\n", $eager);
    }

    #[Test]
    public function searchReturnOptionsProduceEsearch(): void
    {
        $handler = new ImapHandler();
        $handler->onSearch(static function (SearchEvent $event): void {
            $event->accept(match (true) {
                $event->queries()[0]->key === SearchKey::Deleted => [],
                $event->queries()[0]->key === SearchKey::Modseq => ['results' => [2, 4, 5], 'modseqs' => [30, 90, 70]],
                default => [2, 4, 5],
            });
        });
        $conn = $this->selected(new InMemoryNotifier(), [1, 2, 3, 4, 5], $handler);

        self::assertStringContainsString(
            "* ESEARCH (TAG \"a3\") UID MIN 2 MAX 5 ALL 2,4:5 COUNT 3\r\n",
            implode('', $conn->onData("a3 UID SEARCH RETURN (MIN MAX ALL COUNT) ALL\r\n")),
        );
        self::assertStringContainsString(
            "* ESEARCH (TAG \"a4\") ALL 2,4:5\r\n",
            implode('', $conn->onData("a4 SEARCH RETURN () ALL\r\n")),
        );
        self::assertStringContainsString(
            "* ESEARCH (TAG \"a5\") COUNT 0\r\n",
            implode('', $conn->onData("a5 SEARCH RETURN (MIN COUNT) DELETED\r\n")),
        );
        self::assertStringContainsString(' BAD ', implode('', $conn->onData("a6 SEARCH RETURN (SAVE) ALL\r\n")));

        // MODSEQ: the mod-sequence of the messages actually reported.
        self::assertStringContainsString("MIN 2 MODSEQ 30\r\n", implode('', $conn->onData("a7 SEARCH RETURN (MIN) MODSEQ 5\r\n")));
        self::assertStringContainsString("COUNT 3 MODSEQ 90\r\n", implode('', $conn->onData("a8 SEARCH RETURN (COUNT) MODSEQ 5\r\n")));
        self::assertStringContainsString("* SEARCH 2 4 5 (MODSEQ 90)\r\n", implode('', $conn->onData("a9 SEARCH MODSEQ 5\r\n")));
        self::assertStringContainsString("* SEARCH 2 4 5\r\n", implode('', $conn->onData("b1 SEARCH ALL\r\n")));
    }

    #[Test]
    public function condstoreIsEnabledByUseAndShapesStoreAndPushResponses(): void
    {
        $notifier = new InMemoryNotifier();
        $handler = new ImapHandler();
        $handler->onLogin(static function (LoginEvent $event): void {
            $event->accept(CapabilitySet::fromArray(['IMAP4rev1', 'CONDSTORE']));
        });
        $handler->onSelect(static function (SelectEvent $event): void {
            $event->accept(new SelectResult(exists: 2, highestModseq: 40, readWrite: !$event->isReadOnly(), uids: [10, 11]));
        });
        $handler->onStore(static function (\PHPdot\Mail\IMAP\Server\Event\StoreEvent $event): void {
            $event->accept([
                'changed' => [['seq' => 1, 'flags' => ['\\Seen'], 'modseq' => 41]],
                'modified' => $event->unchangedSince() !== null ? [2] : [],
            ]);
        });

        $conn = new ServerConnection($handler, $notifier);
        $conn->onData("a1 LOGIN user pass\r\n");
        $select = implode('', $conn->onData("a2 SELECT INBOX\r\n"));
        self::assertStringContainsString("* OK [HIGHESTMODSEQ 40]", $select);
        self::assertFalse($conn->context()->session()->isCondstoreEnabled());

        // Not enabled: no MODSEQ anywhere.
        self::assertSame(
            "* 1 FETCH (FLAGS (\\Seen))\r\na3 OK STORE completed\r\n",
            implode('', $conn->onData("a3 STORE 1 +FLAGS (\\Seen)\r\n")),
        );
        $notifier->publish('INBOX', MailboxChange::flagChange(11, ['\\Flagged'], 42));
        self::assertStringContainsString("* 2 FETCH (UID 11 FLAGS (\\Flagged))\r\n", implode('', $conn->onData("a4 NOOP\r\n")));

        // A conditional STORE enables CONDSTORE and reports MODIFIED.
        self::assertSame(
            "* 1 FETCH (FLAGS (\\Seen) MODSEQ (41))\r\na5 OK [MODIFIED 2] Conditional STORE failed\r\n",
            implode('', $conn->onData("a5 STORE 1:2 (UNCHANGEDSINCE 39) +FLAGS (\\Seen)\r\n")),
        );
        self::assertTrue($conn->context()->session()->isCondstoreEnabled());

        // From here on: MODSEQ with every flag report, even for .SILENT.
        self::assertSame(
            "* 1 FETCH (MODSEQ (41))\r\na6 OK STORE completed\r\n",
            implode('', $conn->onData("a6 STORE 1 +FLAGS.SILENT (\\Seen)\r\n")),
        );
        $notifier->publish('INBOX', MailboxChange::flagChange(11, [], 43));
        self::assertStringContainsString("* 2 FETCH (UID 11 FLAGS () MODSEQ (43))\r\n", implode('', $conn->onData("a7 NOOP\r\n")));

        self::assertStringContainsString('[PERMANENTFLAGS ()]', implode('', $conn->onData("a8 EXAMINE INBOX\r\n")));
    }

    #[Test]
    public function condstoreIsNotEnabledWhenTheServerDoesNotAdvertiseIt(): void
    {
        $handler = new ImapHandler();
        $handler->onLogin(static function (LoginEvent $event): void {
            $event->accept(CapabilitySet::fromArray(['IMAP4rev1']));
        });
        $handler->onSelect(static function (SelectEvent $event): void {
            $event->accept(new SelectResult(exists: 0, uids: []));
        });

        $conn = new ServerConnection($handler);
        $conn->onData("a1 LOGIN user pass\r\n");
        $conn->onData("a2 SELECT INBOX (CONDSTORE)\r\n");

        self::assertFalse($conn->context()->session()->isCondstoreEnabled());
    }

    #[Test]
    public function sequenceSetMembershipDoesNotExpand(): void
    {
        $set = SequenceSet::fromString('1:3,9,20:*');

        self::assertTrue($set->contains(2, 4_000_000_000));
        self::assertTrue($set->contains(3_999_999_999, 4_000_000_000));
        self::assertFalse($set->contains(10, 4_000_000_000));
        // `*` below the range start: n:* always includes the highest message.
        self::assertTrue(SequenceSet::fromString('50:*')->contains(7, 7));
        self::assertFalse(SequenceSet::fromString('50:*')->contains(6, 7));
    }
}
