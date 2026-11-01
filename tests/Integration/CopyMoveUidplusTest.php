<?php

/**
 * MOVE untagged EXPUNGE (RFC 6851 §3.2) and COPY/MOVE COPYUID (RFC 4315).
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

declare(strict_types=1);

namespace PHPdot\Mail\IMAP\Tests\Integration;

use PHPdot\Mail\IMAP\Connection\ConnectionContext;
use PHPdot\Mail\IMAP\Connection\ServerConnection;
use PHPdot\Mail\IMAP\ImapHandler;
use PHPdot\Mail\IMAP\Result\SelectResult;
use PHPdot\Mail\IMAP\Server\Event\CopyEvent;
use PHPdot\Mail\IMAP\Server\Event\LoginEvent;
use PHPdot\Mail\IMAP\Server\Event\MoveEvent;
use PHPdot\Mail\IMAP\Server\Event\SelectEvent;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CopyMoveUidplusTest extends TestCase
{
    private function connected(ImapHandler $handler): ServerConnection
    {
        $conn = new ServerConnection($handler);
        $conn->greeting();
        $conn->onData("a0 LOGIN user pass\r\n");
        $conn->onData("a1 SELECT INBOX\r\n");
        return $conn;
    }

    private function baseHandler(): ImapHandler
    {
        $handler = new ImapHandler();
        $handler->onLogin(static function (LoginEvent $event, ConnectionContext $ctx): void {
            $event->accept();
        });
        $handler->onSelect(static function (SelectEvent $event, ConnectionContext $ctx): void {
            $event->accept(new SelectResult(exists: 5));
        });
        return $handler;
    }

    #[Test]
    public function moveEmitsUntaggedExpungeBeforeTaggedOk(): void
    {
        $handler = $this->baseHandler();
        $handler->onMove(static function (MoveEvent $event, ConnectionContext $ctx): void {
            $event->accept(['expunged' => [2, 4]]);
        });

        $conn = $this->connected($handler);
        $responses = $conn->onData("a2 MOVE 2,4 Trash\r\n");
        $all = implode('', $responses);

        // RFC 6851: each vacated sequence gets an untagged EXPUNGE, and they
        // precede the tagged OK. Descending order keeps numbering valid.
        $expunge2 = strpos($all, '* 4 EXPUNGE');
        $expunge1 = strpos($all, '* 2 EXPUNGE');
        $ok = strpos($all, 'a2 OK');
        self::assertNotFalse($expunge1);
        self::assertNotFalse($expunge2);
        self::assertNotFalse($ok);
        self::assertLessThan($ok, $expunge1);
        self::assertLessThan($ok, $expunge2);
    }

    #[Test]
    public function copyEmitsCopyUidResponseCode(): void
    {
        $handler = $this->baseHandler();
        $handler->onCopy(static function (CopyEvent $event, ConnectionContext $ctx): void {
            $event->accept(['copyUid' => [3857529045, [101, 102], [201, 202, 203]]]);
        });

        $conn = $this->connected($handler);
        $responses = $conn->onData("a2 COPY 1:2 Archive\r\n");
        $all = implode('', $responses);

        self::assertStringContainsString('[COPYUID 3857529045 101:102 201:203]', $all);
        self::assertStringContainsString('a2 OK', $all);
    }

    #[Test]
    public function moveEmitsCopyUidResponseCode(): void
    {
        $handler = $this->baseHandler();
        $handler->onMove(static function (MoveEvent $event, ConnectionContext $ctx): void {
            $event->accept(['expunged' => [3], 'copyUid' => [3857529045, [105], [204]]]);
        });

        $conn = $this->connected($handler);
        $responses = $conn->onData("a2 MOVE 3 Trash\r\n");
        $all = implode('', $responses);

        self::assertStringContainsString('* 3 EXPUNGE', $all);
        self::assertStringContainsString('[COPYUID 3857529045 105 204]', $all);
    }

    #[Test]
    public function copyWithoutResultIsPlainOk(): void
    {
        $handler = $this->baseHandler();
        $handler->onCopy(static function (CopyEvent $event, ConnectionContext $ctx): void {
            $event->accept();
        });

        $conn = $this->connected($handler);
        $responses = $conn->onData("a2 COPY 1 Archive\r\n");

        self::assertStringContainsString('a2 OK COPY completed', implode('', $responses));
        self::assertStringNotContainsString('COPYUID', implode('', $responses));
    }
}
