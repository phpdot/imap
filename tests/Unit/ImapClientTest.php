<?php
declare(strict_types=1);

namespace PHPdot\Mail\IMAP\Tests\Unit;

use PHPdot\Mail\IMAP\Exception\AuthenticationException;
use PHPdot\Mail\IMAP\Exception\ConnectionException;
use PHPdot\Mail\IMAP\ImapClient;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ImapClientTest extends TestCase
{
    /** @var resource */
    private $clientSide;
    /** @var resource */
    private $serverSide;
    private ImapClient $client;

    protected function setUp(): void
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertIsArray($pair);
        [$this->clientSide, $this->serverSide] = $pair;
        stream_set_blocking($this->serverSide, false);
        $this->client = ImapClient::fromStream($this->clientSide);
    }

    protected function tearDown(): void
    {
        if (is_resource($this->clientSide)) {
            @fclose($this->clientSide);
        }
        if (is_resource($this->serverSide)) {
            @fclose($this->serverSide);
        }
    }

    private function serverWrite(string $data): void
    {
        fwrite($this->serverSide, $data);
    }

    private function serverRead(): string
    {
        return fread($this->serverSide, 65536) ?: '';
    }

    #[Test]
    public function connectReadsGreeting(): void
    {
        $this->serverWrite("* OK IMAP4rev2 Server ready\r\n");
        $this->client->connect();

        $greeting = $this->client->greeting();
        self::assertNotNull($greeting);
        self::assertTrue($greeting->isOk());
    }

    #[Test]
    public function connectWithCapabilityInGreeting(): void
    {
        $this->serverWrite("* OK [CAPABILITY IMAP4rev2 AUTH=PLAIN IDLE] Server ready\r\n");
        $this->client->connect();

        self::assertTrue($this->client->hasCapability('IDLE'));
        self::assertTrue($this->client->hasCapability('AUTH=PLAIN'));
    }

    #[Test]
    public function connectThrowsOnBye(): void
    {
        $this->serverWrite("* BYE Server shutting down\r\n");

        $this->expectException(ConnectionException::class);
        $this->client->connect();
    }

    #[Test]
    public function loginSuccess(): void
    {
        $this->serverWrite("* OK Server ready\r\n");
        $this->client->connect();

        $this->serverWrite("A001 OK LOGIN completed\r\n");
        $this->client->login('user', 'pass');

        $written = $this->serverRead();
        self::assertStringContainsString('LOGIN', $written);
        self::assertStringContainsString('user', $written);
    }

    #[Test]
    public function loginFailure(): void
    {
        $this->serverWrite("* OK Server ready\r\n");
        $this->client->connect();

        $this->serverWrite("A001 NO [AUTHENTICATIONFAILED] Invalid credentials\r\n");

        $this->expectException(AuthenticationException::class);
        $this->client->login('user', 'wrong');
    }

    #[Test]
    public function selectReturnsResult(): void
    {
        $this->serverWrite("* OK Server ready\r\n");
        $this->client->connect();

        $this->serverWrite("A001 OK LOGIN completed\r\n");
        $this->client->login('user', 'pass');

        $this->serverWrite(
            "* 172 EXISTS\r\n" .
            "* 3 RECENT\r\n" .
            "* FLAGS (\\Answered \\Flagged \\Deleted \\Seen \\Draft)\r\n" .
            "A002 OK [READ-WRITE] SELECT completed\r\n"
        );
        $result = $this->client->select('INBOX');

        self::assertSame(172, $result->exists);
        self::assertSame(3, $result->recent);
        self::assertTrue($result->readWrite);
        self::assertNotEmpty($result->flags);
    }

    #[Test]
    public function fetchReturnsResults(): void
    {
        $this->serverWrite("* OK Server ready\r\n");
        $this->client->connect();
        $this->serverWrite("A001 OK LOGIN completed\r\n");
        $this->client->login('user', 'pass');
        $this->serverWrite("A002 OK SELECT completed\r\n");
        $this->client->select('INBOX');

        $this->serverWrite(
            "* 1 FETCH (UID 101 FLAGS (\\Seen))\r\n" .
            "* 2 FETCH (UID 102 FLAGS ())\r\n" .
            "A003 OK FETCH completed\r\n"
        );
        $results = $this->client->fetch('1:2', ['UID', 'FLAGS']);

        self::assertCount(2, $results);
        self::assertSame(101, $results[0]->uid);
        self::assertSame(102, $results[1]->uid);
    }

    #[Test]
    public function searchReturnsUids(): void
    {
        $this->serverWrite("* OK Server ready\r\n");
        $this->client->connect();
        $this->serverWrite("A001 OK LOGIN completed\r\n");
        $this->client->login('user', 'pass');
        $this->serverWrite("A002 OK SELECT completed\r\n");
        $this->client->select('INBOX');

        $this->serverWrite("* SEARCH 1 5 9 14\r\nA003 OK SEARCH completed\r\n");
        $uids = $this->client->search('UNSEEN');

        self::assertSame([1, 5, 9, 14], $uids);
    }

    #[Test]
    public function listMailboxes(): void
    {
        $this->serverWrite("* OK Server ready\r\n");
        $this->client->connect();
        $this->serverWrite("A001 OK LOGIN completed\r\n");
        $this->client->login('user', 'pass');

        $this->serverWrite(
            "* LIST (\\HasNoChildren) \"/\" INBOX\r\n" .
            "* LIST (\\HasNoChildren \\Sent) \"/\" Sent\r\n" .
            "* LIST (\\HasNoChildren \\Trash) \"/\" Trash\r\n" .
            "A002 OK LIST completed\r\n"
        );
        $folders = $this->client->listMailboxes();

        self::assertCount(3, $folders);
        self::assertSame('INBOX', $folders[0]->mailbox->name);
        self::assertSame('Sent', $folders[1]->mailbox->name);
    }

    #[Test]
    public function statusReturnsInfo(): void
    {
        $this->serverWrite("* OK Server ready\r\n");
        $this->client->connect();
        $this->serverWrite("A001 OK LOGIN completed\r\n");
        $this->client->login('user', 'pass');

        $this->serverWrite(
            "* STATUS INBOX (MESSAGES 172 UNSEEN 12)\r\n" .
            "A002 OK STATUS completed\r\n"
        );
        $status = $this->client->status('INBOX', ['MESSAGES', 'UNSEEN']);

        self::assertSame(172, $status->messages);
        self::assertSame(12, $status->unseen);
    }

    #[Test]
    public function storeFlags(): void
    {
        $this->serverWrite("* OK Server ready\r\n");
        $this->client->connect();
        $this->serverWrite("A001 OK LOGIN completed\r\n");
        $this->client->login('user', 'pass');
        $this->serverWrite("A002 OK SELECT completed\r\n");
        $this->client->select('INBOX');

        $this->serverWrite("A003 OK STORE completed\r\n");
        $this->client->store('1:3', '+FLAGS', ['\\Seen']);

        $written = $this->serverRead();
        self::assertStringContainsString('STORE', $written);
        self::assertStringContainsString('+FLAGS', $written);
        self::assertStringContainsString('\\Seen', $written);
    }

    #[Test]
    public function logoutClosesConnection(): void
    {
        $this->serverWrite("* OK Server ready\r\n");
        $this->client->connect();
        $this->serverWrite("A001 OK LOGIN completed\r\n");
        $this->client->login('user', 'pass');

        $this->serverWrite("* BYE Server logging out\r\nA002 OK LOGOUT completed\r\n");
        $this->client->logout();

        self::assertFalse($this->client->isConnected());
    }

    #[Test]
    public function noopWorks(): void
    {
        $this->serverWrite("* OK Server ready\r\n");
        $this->client->connect();

        $this->serverWrite("A001 OK NOOP completed\r\n");
        $this->client->noop();
        self::assertTrue($this->client->isConnected());
    }

    #[Test]
    public function expungeReturnsSequenceNumbers(): void
    {
        $this->serverWrite("* OK Server ready\r\n");
        $this->client->connect();
        $this->serverWrite("A001 OK LOGIN completed\r\n");
        $this->client->login('user', 'pass');
        $this->serverWrite("A002 OK SELECT completed\r\n");
        $this->client->select('INBOX');

        $this->serverWrite("* 3 EXPUNGE\r\n* 5 EXPUNGE\r\nA003 OK EXPUNGE completed\r\n");
        $expunged = $this->client->expunge();

        self::assertSame([3, 5], $expunged);
    }

    #[Test]
    public function enableTracksState(): void
    {
        $this->serverWrite("* OK Server ready\r\n");
        $this->client->connect();
        $this->serverWrite("A001 OK LOGIN completed\r\n");
        $this->client->login('user', 'pass');

        $this->serverWrite("* ENABLED CONDSTORE\r\nA002 OK ENABLE completed\r\n");
        $enabled = $this->client->enable(['CONDSTORE']);

        self::assertContains('CONDSTORE', $enabled);
        self::assertTrue($this->client->session()->isCondstoreEnabled());
    }

    #[Test]
    public function fullSessionFlow(): void
    {
        $this->serverWrite("* OK [CAPABILITY IMAP4rev2] Server ready\r\n");
        $this->client->connect();

        $this->serverWrite("A001 OK LOGIN completed\r\n");
        $this->client->login('user', 'pass');

        $this->serverWrite("* 5 EXISTS\r\n* FLAGS (\\Seen \\Flagged)\r\nA002 OK [READ-WRITE] SELECT completed\r\n");
        $inbox = $this->client->select('INBOX');
        self::assertSame(5, $inbox->exists);

        $this->serverWrite("* 1 FETCH (UID 100 FLAGS (\\Seen))\r\nA003 OK FETCH completed\r\n");
        $messages = $this->client->fetch('1', ['UID', 'FLAGS']);
        self::assertCount(1, $messages);
        self::assertSame(100, $messages[0]->uid);

        $this->serverWrite("* SEARCH 1 3 5\r\nA004 OK SEARCH completed\r\n");
        $uids = $this->client->search('UNSEEN');
        self::assertSame([1, 3, 5], $uids);

        $this->serverWrite("A005 OK STORE completed\r\n");
        $this->client->store('1:3', '+FLAGS', ['\\Seen']);

        $this->serverWrite("A006 OK CLOSE completed\r\n");
        $this->client->close();

        $this->serverWrite("* BYE\r\nA007 OK LOGOUT completed\r\n");
        $this->client->logout();
    }
}
