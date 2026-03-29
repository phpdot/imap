<?php
declare(strict_types=1);

namespace PHPdot\Mail\IMAP\Tests\Integration;

use PHPdot\Mail\IMAP\Connection\ConnectionContext;
use PHPdot\Mail\IMAP\Connection\ServerConnection;
use PHPdot\Mail\IMAP\ImapClient;
use PHPdot\Mail\IMAP\ImapHandler;
use PHPdot\Mail\IMAP\Result\SelectResult;
use PHPdot\Mail\IMAP\Server\Event\LoginEvent;
use PHPdot\Mail\IMAP\Server\Event\SelectEvent;
use PHPdot\Mail\IMAP\DataType\ValueObject\Flag;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests the high-level ImapClient talking to ServerConnection via stream_socket_pair.
 */
final class HighLevelApiTest extends TestCase
{
    /** @var resource */
    private $clientSide;
    /** @var resource */
    private $serverSide;
    private ImapClient $client;
    private ServerConnection $serverConn;

    protected function setUp(): void
    {
        $handler = new ImapHandler();

        $handler->onLogin(function (LoginEvent $event, ConnectionContext $ctx): void {
            if ($event->username() === 'testuser' && $event->password() === 'testpass') {
                $event->accept();
            } else {
                $event->reject('Invalid credentials');
            }
        });

        $handler->onSelect(function (SelectEvent $event, ConnectionContext $ctx): void {
            $event->accept(new SelectResult(
                exists: 42,
                recent: 2,
                flags: [new Flag('\\Seen'), new Flag('\\Flagged'), new Flag('\\Deleted')],
                permanentFlags: [new Flag('\\Seen'), new Flag('\\Flagged'), new Flag('\\Deleted')],
                uidValidity: 12345,
                uidNext: 100,
                readWrite: true,
            ));
        });

        $this->serverConn = new ServerConnection($handler);

        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertIsArray($pair);
        [$this->clientSide, $this->serverSide] = $pair;
        stream_set_blocking($this->serverSide, false);
        $this->client = ImapClient::fromStream($this->clientSide);
    }

    protected function tearDown(): void
    {
        @fclose($this->clientSide);
        @fclose($this->serverSide);
    }

    private function feedToClient(string $data): void
    {
        fwrite($this->serverSide, $data);
    }

    #[Test]
    public function clientConnectsToServer(): void
    {
        $this->feedToClient($this->serverConn->greeting());
        $this->client->connect();

        $g = $this->client->greeting();
        self::assertNotNull($g);
        self::assertTrue($g->isOk());
    }

    #[Test]
    public function loginThroughServerHandler(): void
    {
        $this->feedToClient($this->serverConn->greeting());
        $this->client->connect();

        $loginResponses = $this->serverConn->onData("A001 LOGIN testuser testpass\r\n");
        foreach ($loginResponses as $r) {
            $this->feedToClient($r);
        }
        $this->client->login('testuser', 'testpass');

        self::assertTrue($this->serverConn->isAuthenticated());
    }

    #[Test]
    public function selectThroughServerHandler(): void
    {
        $this->feedToClient($this->serverConn->greeting());
        $this->client->connect();

        $loginResponses = $this->serverConn->onData("A001 LOGIN testuser testpass\r\n");
        foreach ($loginResponses as $r) {
            $this->feedToClient($r);
        }
        $this->client->login('testuser', 'testpass');

        $selectResponses = $this->serverConn->onData("A002 SELECT INBOX\r\n");
        foreach ($selectResponses as $r) {
            $this->feedToClient($r);
        }
        $result = $this->client->select('INBOX');

        self::assertSame('INBOX', $this->serverConn->selectedMailbox());
    }
}
