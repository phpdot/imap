<?php
/**
 * Real-world use case tests for complete IMAP workflows.
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
use PHPdot\Mail\IMAP\Client\Parser\ResponseInterpreter;
use PHPdot\Mail\IMAP\DataType\DTO\Address;
use PHPdot\Mail\IMAP\DataType\DTO\BodyStructure;
use PHPdot\Mail\IMAP\DataType\DTO\BodyStructurePart;
use PHPdot\Mail\IMAP\DataType\DTO\Envelope;
use PHPdot\Mail\IMAP\DataType\DTO\FetchResult;
use PHPdot\Mail\IMAP\DataType\DTO\MailboxInfo;
use PHPdot\Mail\IMAP\DataType\DTO\StatusInfo;
use PHPdot\Mail\IMAP\DataType\Enum\ConnectionState;
use PHPdot\Mail\IMAP\DataType\Enum\ContentEncoding;
use PHPdot\Mail\IMAP\DataType\Enum\ResponseStatus;
use PHPdot\Mail\IMAP\DataType\ValueObject\CapabilitySet;
use PHPdot\Mail\IMAP\DataType\ValueObject\Flag;
use PHPdot\Mail\IMAP\DataType\ValueObject\ImapDateTime;
use PHPdot\Mail\IMAP\Protocol\WireFormat;
use PHPdot\Mail\IMAP\Server\Event\AuthenticateEvent;
use PHPdot\Mail\IMAP\Server\Event\FetchEvent;
use PHPdot\Mail\IMAP\Server\Event\IdleEvent;
use PHPdot\Mail\IMAP\Server\Event\ListEvent;
use PHPdot\Mail\IMAP\Server\Event\LoginEvent;
use PHPdot\Mail\IMAP\Server\Event\SearchEvent;
use PHPdot\Mail\IMAP\Server\Event\SelectEvent;
use PHPdot\Mail\IMAP\Server\Event\SimpleEvent;
use PHPdot\Mail\IMAP\Server\Event\StoreEvent;
use PHPdot\Mail\IMAP\Server\Event\AppendEvent;
use PHPdot\Mail\IMAP\Server\Event\CopyEvent;
use PHPdot\Mail\IMAP\Server\Event\MoveEvent;
use PHPdot\Mail\IMAP\Server\Response\ResponseBuilder;
use PHPdot\Mail\IMAP\Server\ServerProtocol;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Real-world use case tests proving the library handles complete workflows.
 */
final class UseCaseTest extends TestCase
{
    private ServerProtocol $server;
    private ClientProtocol $client;
    private ResponseInterpreter $interpreter;
    /** @var list<string> */
    private array $clientLog;

    protected function setUp(): void
    {
        $this->server = new ServerProtocol();
        $this->client = new ClientProtocol();
        $this->interpreter = new ResponseInterpreter();
        $this->clientLog = [];

        $this->client->on(GreetingEvent::class, function () {});
        $this->client->on(TaggedResponseEvent::class, function (TaggedResponseEvent $e): void {
            $this->clientLog[] = 'TAGGED:' . $e->taggedResponse->status->value;
        });
        $this->client->on(DataEvent::class, function (DataEvent $e): void {
            $this->clientLog[] = 'DATA:' . $e->type();
        });
    }

    private function sendToServer(string $bytes): void
    {
        foreach ($this->server->onData($bytes) as $r) {
            $this->client->onData($r);
        }
    }

    // ============================================================
    // USE CASE 1: Login + Select + Fetch envelope + Logout
    // ============================================================
    #[Test]
    public function useCase1_readEmail(): void
    {
        $this->server->on(LoginEvent::class, fn(LoginEvent $e) => $e->accept());
        $this->server->on(SelectEvent::class, function (SelectEvent $e): void {
            // Server would send EXISTS, FLAGS, UIDVALIDITY etc before OK
            $e->accept();
        });
        $this->server->on(FetchEvent::class, fn(FetchEvent $e) => $e->accept());
        $this->server->on(SimpleEvent::class, fn(SimpleEvent $e) => $e->accept());

        // Greeting
        $this->client->onData($this->server->greeting());

        // Login
        [$tag, $bytes] = $this->client->command()->login('user', 'pass');
        $this->sendToServer($bytes);
        self::assertSame(ConnectionState::Authenticated, $this->server->state());

        // Select
        [$tag, $bytes] = $this->client->command()->select('INBOX');
        $this->sendToServer($bytes);
        self::assertSame(ConnectionState::Selected, $this->server->state());

        // Fetch
        [$tag, $bytes] = $this->client->command()->uidFetch('1:*', '(FLAGS ENVELOPE)');
        $this->sendToServer($bytes);

        // Logout
        [$tag, $bytes] = $this->client->command()->logout();
        $this->sendToServer($bytes);
        self::assertTrue($this->server->isLoggedOut());
    }

    // ============================================================
    // USE CASE 2: OAuth2 authentication (XOAUTH2)
    // ============================================================
    #[Test]
    public function useCase2_oauth2Login(): void
    {
        $this->server->on(AuthenticateEvent::class, function (AuthenticateEvent $e): void {
            self::assertSame('XOAUTH2', $e->mechanism());
            self::assertNotNull($e->initialResponse());
            // In real life: decode base64, validate token with Google
            $e->accept();
        });
        $this->server->on(SimpleEvent::class, fn(SimpleEvent $e) => $e->accept());

        $this->client->onData($this->server->greeting());

        // AUTHENTICATE XOAUTH2 with SASL-IR (initial response inline)
        $token = base64_encode("user=user@gmail.com\x01auth=Bearer ya29.token\x01\x01");
        [$tag, $bytes] = $this->client->command()->authenticate('XOAUTH2', $token);
        $this->sendToServer($bytes);

        self::assertSame(ConnectionState::Authenticated, $this->server->state());
    }

    // ============================================================
    // USE CASE 3: Search + Store flags + Expunge (delete workflow)
    // ============================================================
    #[Test]
    public function useCase3_deleteMessages(): void
    {
        $this->server->on(LoginEvent::class, fn(LoginEvent $e) => $e->accept());
        $this->server->on(SelectEvent::class, fn(SelectEvent $e) => $e->accept());
        $this->server->on(SearchEvent::class, function (SearchEvent $e): void {
            self::assertTrue($e->isUid());
            $e->accept();
        });
        $this->server->on(StoreEvent::class, function (StoreEvent $e): void {
            self::assertSame('add', $e->storeCommand->operation->action());
            $e->accept();
        });
        $this->server->on(SimpleEvent::class, fn(SimpleEvent $e) => $e->accept());

        $this->client->onData($this->server->greeting());

        [$t, $b] = $this->client->command()->login('user', 'pass');
        $this->sendToServer($b);

        [$t, $b] = $this->client->command()->select('INBOX');
        $this->sendToServer($b);

        // Search for messages from spammer
        [$t, $b] = $this->client->command()->uidSearch('FROM "spammer@evil.com"');
        $this->sendToServer($b);

        // Mark as deleted
        [$t, $b] = $this->client->command()->uidStore('1:5', '+FLAGS', '(\\Deleted)');
        $this->sendToServer($b);

        // Expunge
        [$t, $b] = $this->client->command()->expunge();
        $this->sendToServer($b);

        self::assertContains('TAGGED:OK', $this->clientLog);
    }

    // ============================================================
    // USE CASE 4: Copy/Move between folders
    // ============================================================
    #[Test]
    public function useCase4_moveToArchive(): void
    {
        $this->server->on(LoginEvent::class, fn(LoginEvent $e) => $e->accept());
        $this->server->on(SelectEvent::class, fn(SelectEvent $e) => $e->accept());
        $this->server->on(CopyEvent::class, function (CopyEvent $e): void {
            self::assertSame('Archive', $e->copyCommand->destination->name);
            $e->accept();
        });
        $this->server->on(MoveEvent::class, function (MoveEvent $e): void {
            self::assertSame('Trash', $e->moveCommand->destination->name);
            $e->accept();
        });
        $this->server->on(SimpleEvent::class, fn(SimpleEvent $e) => $e->accept());

        $this->client->onData($this->server->greeting());

        [$t, $b] = $this->client->command()->login('user', 'pass');
        $this->sendToServer($b);

        [$t, $b] = $this->client->command()->select('INBOX');
        $this->sendToServer($b);

        // Copy messages to Archive
        [$t, $b] = $this->client->command()->copy('1:10', 'Archive');
        $this->sendToServer($b);

        // Move messages to Trash
        [$t, $b] = $this->client->command()->move('11:15', 'Trash');
        $this->sendToServer($b);

        self::assertContains('TAGGED:OK', $this->clientLog);
    }

    // ============================================================
    // USE CASE 5: Append a new message (draft save)
    // ============================================================
    #[Test]
    public function useCase5_saveDraft(): void
    {
        $this->server->on(LoginEvent::class, fn(LoginEvent $e) => $e->accept());
        $this->server->on(AppendEvent::class, function (AppendEvent $e): void {
            self::assertSame('Drafts', $e->appendCommand->mailbox->name);
            self::assertStringContainsString('Subject: Draft', $e->appendCommand->message);
            self::assertCount(1, $e->appendCommand->flags);
            $e->accept();
        });
        $this->server->on(SimpleEvent::class, fn(SimpleEvent $e) => $e->accept());

        $this->client->onData($this->server->greeting());

        [$t, $b] = $this->client->command()->login('user', 'pass');
        $this->sendToServer($b);

        // Save a draft
        $message = "From: user@example.com\r\nSubject: Draft email\r\n\r\nWork in progress...";
        [$t, $b] = $this->client->command()->append('Drafts', $message, [new Flag('\\Draft')]);
        $this->sendToServer($b);

        self::assertContains('TAGGED:OK', $this->clientLog);
    }

    // ============================================================
    // USE CASE 6: IDLE with push notification
    // ============================================================
    #[Test]
    public function useCase6_idleWithPush(): void
    {
        $this->server->on(LoginEvent::class, fn(LoginEvent $e) => $e->accept());
        $this->server->on(SelectEvent::class, fn(SelectEvent $e) => $e->accept());
        $this->server->on(IdleEvent::class, function (IdleEvent $e): void {
            $e->accept();
            // Simulate new message arriving
            $e->push('5 EXISTS');
        });
        $this->server->on(SimpleEvent::class, fn(SimpleEvent $e) => $e->accept());

        $this->client->onData($this->server->greeting());

        [$t, $b] = $this->client->command()->login('user', 'pass');
        $this->sendToServer($b);

        [$t, $b] = $this->client->command()->select('INBOX');
        $this->sendToServer($b);

        // Start IDLE
        [$tag, $bytes] = $this->client->command()->idle();
        $this->sendToServer($bytes);

        self::assertTrue($this->server->isIdling());

        // Send DONE
        $doneBytes = $this->client->command()->done();
        $responses = $this->server->onData($doneBytes);
        foreach ($responses as $r) {
            $this->client->onData($r);
        }

        self::assertFalse($this->server->isIdling());
        // Should have EXISTS notification + OK
        $hasExists = false;
        $hasOk = false;
        foreach ($responses as $r) {
            if (str_contains($r, 'EXISTS')) {
                $hasExists = true;
            }
            if (str_contains($r, 'OK')) {
                $hasOk = true;
            }
        }
        self::assertTrue($hasExists, 'Missing EXISTS notification');
        self::assertTrue($hasOk, 'Missing OK response');
    }

    // ============================================================
    // USE CASE 7: Protocol negotiation (rev1 → rev2)
    // ============================================================
    #[Test]
    public function useCase7_protocolNegotiation(): void
    {
        $this->server->on(LoginEvent::class, fn(LoginEvent $e) => $e->accept());
        $this->server->on(SimpleEvent::class, fn(SimpleEvent $e) => $e->accept());

        $this->client->onData($this->server->greeting());

        // Both start as rev1
        self::assertTrue($this->server->session()->isRev1());
        self::assertTrue($this->client->session()->isRev1());

        [$t, $b] = $this->client->command()->login('user', 'pass');
        $this->sendToServer($b);

        // Enable rev2
        [$t, $b] = $this->client->command()->enable(['IMAP4REV2', 'CONDSTORE', 'UTF8=ACCEPT']);
        $this->sendToServer($b);

        // Both should be rev2
        self::assertTrue($this->server->session()->isRev2());
        self::assertTrue($this->client->session()->isRev2());
        self::assertTrue($this->server->session()->isCondstoreEnabled());
        self::assertTrue($this->client->session()->isCondstoreEnabled());
        self::assertFalse($this->server->session()->useUtf7Mailbox());
    }

    // ============================================================
    // USE CASE 8: Client interprets FETCH response
    // ============================================================
    #[Test]
    public function useCase8_clientInterpretsFetch(): void
    {
        // Simulate server sending a FETCH response
        $fetchResult = new FetchResult(
            sequenceNumber: 1,
            uid: 101,
            flags: [new Flag('\\Seen'), new Flag('\\Flagged')],
            envelope: new Envelope(
                date: 'Mon, 7 Feb 1994 21:52:25 -0800',
                subject: 'Test Subject',
                from: [new Address('Alice', null, 'alice', 'example.com')],
                sender: null,
                replyTo: null,
                to: [new Address('Bob', null, 'bob', 'example.com')],
                cc: null,
                bcc: null,
                inReplyTo: null,
                messageId: '<msg001@example.com>',
            ),
            rfc822Size: 4423,
        );

        $wire = WireFormat::fetchResponse($fetchResult);

        // Client parses it
        /** @var list<FetchResult> $results */
        $results = [];
        $this->client->on(GreetingEvent::class, function () {});
        $this->client->on(DataEvent::class, function (DataEvent $e) use (&$results): void {
            if ($e->data->isFetch()) {
                $results[] = $this->interpreter->interpretFetch($e->data);
            }
        });

        $this->client->onData("* OK Server ready\r\n");
        $this->client->onData($wire);

        self::assertCount(1, $results);
        $result = $results[0];
        self::assertSame(1, $result->sequenceNumber);
        self::assertSame(101, $result->uid);
        self::assertNotNull($result->flags);
        self::assertCount(2, $result->flags);
        self::assertNotNull($result->envelope);
        self::assertSame('Test Subject', $result->envelope->subject);
        self::assertNotNull($result->envelope->from);
        self::assertSame('alice', $result->envelope->from[0]->mailbox);
        self::assertSame(4423, $result->rfc822Size);
    }

    // ============================================================
    // USE CASE 9: Client interprets LIST response
    // ============================================================
    #[Test]
    public function useCase9_clientInterpretsList(): void
    {
        /** @var list<MailboxInfo> $mailboxes */
        $mailboxes = [];
        $this->client->on(GreetingEvent::class, function () {});
        $this->client->on(DataEvent::class, function (DataEvent $e) use (&$mailboxes): void {
            if ($e->data->isList()) {
                $mailboxes[] = $this->interpreter->interpretList($e->data);
            }
        });

        $this->client->onData("* OK Server ready\r\n");
        $this->client->onData("* LIST (\\HasNoChildren) \"/\" INBOX\r\n");
        $this->client->onData("* LIST (\\HasNoChildren \\Sent) \"/\" Sent\r\n");
        $this->client->onData("* LIST (\\HasChildren) \"/\" Archive\r\n");
        $this->client->onData("* LIST (\\HasNoChildren \\Trash) \"/\" Trash\r\n");

        self::assertCount(4, $mailboxes);
        self::assertSame('INBOX', $mailboxes[0]->mailbox->name);
        self::assertSame('/', $mailboxes[0]->delimiter);
        self::assertSame('Sent', $mailboxes[1]->mailbox->name);
        self::assertTrue($mailboxes[2]->hasChildren());
        self::assertFalse($mailboxes[0]->hasChildren());
    }

    // ============================================================
    // USE CASE 10: Client interprets STATUS response
    // ============================================================
    #[Test]
    public function useCase10_clientInterpretsStatus(): void
    {
        /** @var StatusInfo|null $status */
        $status = null;
        $this->client->on(GreetingEvent::class, function () {});
        $this->client->on(DataEvent::class, function (DataEvent $e) use (&$status): void {
            if ($e->data->isStatus()) {
                $status = $this->interpreter->interpretStatus($e->data);
            }
        });

        $this->client->onData("* OK Server ready\r\n");
        $this->client->onData("* STATUS INBOX (MESSAGES 172 UIDNEXT 4392 UIDVALIDITY 3857529045 UNSEEN 12)\r\n");

        self::assertNotNull($status);
        self::assertSame('INBOX', $status->mailbox->name);
        self::assertSame(172, $status->messages);
        self::assertSame(4392, $status->uidNext);
        self::assertSame(3857529045, $status->uidValidity);
        self::assertSame(12, $status->unseen);
    }

    // ============================================================
    // USE CASE 11: Server push notifications (unsolicited responses)
    // ============================================================
    #[Test]
    public function useCase11_serverPushNotifications(): void
    {
        // Server can generate push notifications anytime
        $exists = $this->server->pushExists(173);
        self::assertSame("* 173 EXISTS\r\n", $exists);

        $expunge = $this->server->pushExpunge(5);
        self::assertSame("* 5 EXPUNGE\r\n", $expunge);

        $flagUpdate = $this->server->pushFlagUpdate(3, 1055, [
            new Flag('\\Seen'),
            new Flag('\\Flagged'),
        ]);
        self::assertStringContainsString('* 3 FETCH', $flagUpdate);
        self::assertStringContainsString('UID 1055', $flagUpdate);
        self::assertStringContainsString('\\Seen', $flagUpdate);

        // Client can parse these
        /** @var list<string> $notifications */
        $notifications = [];
        $client = new ClientProtocol();
        $client->on(GreetingEvent::class, function () {});
        $client->on(DataEvent::class, function (DataEvent $e) use (&$notifications): void {
            $notifications[] = $e->type() . ':' . ($e->data->number ?? 'null');
        });

        $client->onData("* OK Server ready\r\n");
        $client->onData($exists);
        $client->onData($expunge);
        $client->onData($flagUpdate);

        self::assertContains('EXISTS:173', $notifications);
        self::assertContains('EXPUNGE:5', $notifications);
        self::assertContains('FETCH:3', $notifications);
    }

    // ============================================================
    // USE CASE 12: CAPABILITY handling
    // ============================================================
    #[Test]
    public function useCase12_capabilityHandling(): void
    {
        // Server: consumer provides capabilities
        $this->server->on(SimpleEvent::class, function (SimpleEvent $e): void {
            if ($e->command->name === 'CAPABILITY') {
                $e->accept(CapabilitySet::fromArray([
                    'IMAP4rev1',
                    'IMAP4rev2',
                    'AUTH=XOAUTH2',
                    'IDLE',
                    'MOVE',
                    'LOGINDISABLED',
                ]));
                return;
            }
            $e->accept();
        });
        $this->server->on(LoginEvent::class, fn(LoginEvent $e) => $e->accept());

        // Client: detect capabilities
        /** @var CapabilitySet|null $caps */
        $caps = null;
        $client = new ClientProtocol();
        $client->on(GreetingEvent::class, function () {});
        $client->on(TaggedResponseEvent::class, function () {});
        $client->on(DataEvent::class, function (DataEvent $e) use (&$caps): void {
            if ($e->data->isCapability()) {
                $caps = (new ResponseInterpreter())->interpretCapability($e->data);
            }
        });

        $client->onData($this->server->greeting());

        // Request capabilities
        [$tag, $bytes] = $client->command()->capability();
        foreach ($this->server->onData($bytes) as $r) {
            $client->onData($r);
        }

        self::assertNotNull($caps);
        self::assertTrue($caps->has('IMAP4REV2'));
        self::assertTrue($caps->has('IDLE'));
        self::assertTrue($caps->hasAuth('XOAUTH2'));
        self::assertTrue($caps->has('LOGINDISABLED'));
    }

    // ============================================================
    // USE CASE 13: Login rejected
    // ============================================================
    #[Test]
    public function useCase13_loginRejected(): void
    {
        $this->server->on(LoginEvent::class, function (LoginEvent $e): void {
            $e->reject('Invalid credentials', ResponseStatus::No,
                \PHPdot\Mail\IMAP\DataType\Enum\ResponseCode::AuthenticationFailed);
        });

        $this->client->onData($this->server->greeting());

        [$tag, $bytes] = $this->client->command()->login('user', 'wrong');
        $this->sendToServer($bytes);

        self::assertContains('TAGGED:NO', $this->clientLog);
        self::assertSame(ConnectionState::NotAuthenticated, $this->server->state());
    }

    // ============================================================
    // USE CASE 14: Namespace discovery
    // ============================================================
    #[Test]
    public function useCase14_namespaceDiscovery(): void
    {
        $this->server->on(LoginEvent::class, fn(LoginEvent $e) => $e->accept());
        $this->server->on(SimpleEvent::class, fn(SimpleEvent $e) => $e->accept());

        $this->client->onData($this->server->greeting());

        [$t, $b] = $this->client->command()->login('user', 'pass');
        $this->sendToServer($b);

        [$t, $b] = $this->client->command()->namespace();
        $this->sendToServer($b);

        self::assertContains('TAGGED:OK', $this->clientLog);
    }

    // ============================================================
    // USE CASE 15: Full webmail session
    // ============================================================
    #[Test]
    public function useCase15_fullWebmailSession(): void
    {
        $this->server->on(LoginEvent::class, fn(LoginEvent $e) => $e->accept());
        $this->server->on(SelectEvent::class, fn(SelectEvent $e) => $e->accept());
        $this->server->on(FetchEvent::class, fn(FetchEvent $e) => $e->accept());
        $this->server->on(SearchEvent::class, fn(SearchEvent $e) => $e->accept());
        $this->server->on(StoreEvent::class, fn(StoreEvent $e) => $e->accept());
        $this->server->on(CopyEvent::class, fn(CopyEvent $e) => $e->accept());
        $this->server->on(MoveEvent::class, fn(MoveEvent $e) => $e->accept());
        $this->server->on(AppendEvent::class, fn(AppendEvent $e) => $e->accept());
        $this->server->on(ListEvent::class, fn(ListEvent $e) => $e->accept());
        $this->server->on(SimpleEvent::class, fn(SimpleEvent $e) => $e->accept());

        $this->client->onData($this->server->greeting());

        // Login
        [$t, $b] = $this->client->command()->login('user', 'pass');
        $this->sendToServer($b);

        // Enable rev2
        [$t, $b] = $this->client->command()->enable(['IMAP4REV2']);
        $this->sendToServer($b);

        // List folders
        [$t, $b] = $this->client->command()->list('', '*');
        $this->sendToServer($b);

        // Select inbox
        [$t, $b] = $this->client->command()->select('INBOX');
        $this->sendToServer($b);

        // Fetch message list
        [$t, $b] = $this->client->command()->uidFetch('1:50', '(FLAGS ENVELOPE RFC822.SIZE)');
        $this->sendToServer($b);

        // Read a message body
        [$t, $b] = $this->client->command()->uidFetch('42', '(BODY.PEEK[])');
        $this->sendToServer($b);

        // Mark as seen
        [$t, $b] = $this->client->command()->uidStore('42', '+FLAGS', '(\\Seen)');
        $this->sendToServer($b);

        // Search
        [$t, $b] = $this->client->command()->uidSearch('UNSEEN');
        $this->sendToServer($b);

        // Archive old messages
        [$t, $b] = $this->client->command()->uidMove('1:10', 'Archive');
        $this->sendToServer($b);

        // Save a draft
        $draft = "From: user@example.com\r\nSubject: Reply\r\n\r\nBody";
        [$t, $b] = $this->client->command()->append('Drafts', $draft, [new Flag('\\Draft')]);
        $this->sendToServer($b);

        // Close and logout
        [$t, $b] = $this->client->command()->close();
        $this->sendToServer($b);

        [$t, $b] = $this->client->command()->logout();
        $this->sendToServer($b);

        self::assertTrue($this->server->isLoggedOut());
        // Should have no errors — all OK
        self::assertNotContains('TAGGED:NO', $this->clientLog);
        self::assertNotContains('TAGGED:BAD', $this->clientLog);
    }
}
