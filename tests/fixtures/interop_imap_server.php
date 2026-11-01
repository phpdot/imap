<?php

/**
 * Interop fixture: an IMAPS server with a real in-memory mailbox store —
 * the shape a production storage backend will take.
 *
 * Usage: php interop_imap_server.php <port> <cert.pem> <key.pem>
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

declare(strict_types=1);

$autoload = __DIR__ . '/../../vendor/autoload.php';
if (!is_file($autoload)) {
    $autoload = __DIR__ . '/../../../../vendor/autoload.php';
}
require $autoload;

use PHPdot\Mail\IMAP\Connection\ConnectionContext;
use PHPdot\Mail\IMAP\DataType\DTO\Address;
use PHPdot\Mail\IMAP\DataType\DTO\Envelope;
use PHPdot\Mail\IMAP\DataType\DTO\FetchResult;
use PHPdot\Mail\IMAP\DataType\DTO\MailboxInfo;
use PHPdot\Mail\IMAP\DataType\Enum\MailboxAttribute;
use PHPdot\Mail\IMAP\DataType\ValueObject\Flag;
use PHPdot\Mail\IMAP\DataType\ValueObject\Mailbox;
use PHPdot\Mail\IMAP\ImapHandler;
use PHPdot\Mail\IMAP\Result\SelectResult;
use PHPdot\Mail\IMAP\Server\Event\AppendEvent;
use PHPdot\Mail\IMAP\Server\Event\FetchEvent;
use PHPdot\Mail\IMAP\Server\Event\ListEvent;
use PHPdot\Mail\IMAP\Server\Event\LoginEvent;
use PHPdot\Mail\IMAP\Server\Event\SearchEvent;
use PHPdot\Mail\IMAP\Server\Event\SelectEvent;
use PHPdot\Mail\IMAP\Server\Event\StoreEvent;
use PHPdot\Mail\IMAP\Server\StreamServer;

$rawArgv = $_SERVER['argv'] ?? [];
$argv = [];
if (is_array($rawArgv)) {
    foreach ($rawArgv as $arg) {
        if (is_string($arg)) {
            $argv[] = $arg;
        }
    }
}

$port = (int) ($argv[1] ?? 0);
$certFile = $argv[2] ?? '';
$keyFile = $argv[3] ?? '';

if ($port === 0 || $certFile === '' || $keyFile === '') {
    fwrite(STDERR, "usage: php interop_imap_server.php <port> <cert.pem> <key.pem>\n");
    exit(1);
}

// In-memory message store: seq => [uid, flags, envelope, size, body]
$store = [
    1 => [
        'uid' => 101,
        'flags' => [],
        'envelope' => new Envelope(
            date: 'Mon, 29 Sep 2026 10:00:00 +0300',
            subject: 'Welcome to the platform',
            from: [new Address('Omar Hamdan', null, 'omar', 'example.com')],
            sender: null,
            replyTo: null,
            to: [new Address(null, null, 'team', 'example.com')],
            cc: null,
            bcc: null,
            inReplyTo: null,
            messageId: '<welcome@example.com>',
        ),
        'size' => 220,
        'body' => "Subject: Welcome to the platform\r\n\r\nHello from the interop fixture.\r\n",
    ],
    2 => [
        'uid' => 102,
        'flags' => [new Flag('\\Seen')],
        'envelope' => new Envelope(
            date: 'Mon, 29 Sep 2026 11:30:00 +0300',
            subject: 'Re: deployment plan',
            from: [new Address('Dana', null, 'dana', 'example.net')],
            sender: null,
            replyTo: null,
            to: [new Address(null, null, 'omar', 'example.com')],
            cc: null,
            bcc: null,
            inReplyTo: '<welcome@example.com>',
            messageId: '<re1@example.net>',
        ),
        'size' => 180,
        'body' => "Subject: Re: deployment plan\r\n\r\nSounds good, ship it.\r\n",
    ],
];
$nextUid = 103;
$uidValidity = 3857529045;

$handler = new ImapHandler();

$handler->onLogin(static function (LoginEvent $event, ConnectionContext $ctx): void {
    if ($event->username() === 'user' && $event->password() === 'pass') {
        $event->accept();
        return;
    }
    $event->reject('Invalid credentials');
});

$handler->onList(static function (ListEvent $event, ConnectionContext $ctx): void {
    $event->accept([
        new MailboxInfo(new Mailbox('INBOX'), '/', [MailboxAttribute::HasNoChildren]),
        new MailboxInfo(new Mailbox('Sent'), '/', [MailboxAttribute::HasNoChildren, MailboxAttribute::Sent]),
        new MailboxInfo(new Mailbox('Trash'), '/', [MailboxAttribute::HasNoChildren, MailboxAttribute::Trash]),
        new MailboxInfo(new Mailbox('Junk'), '/', [MailboxAttribute::HasNoChildren, MailboxAttribute::Junk]),
        new MailboxInfo(new Mailbox('Drafts'), '/', [MailboxAttribute::HasNoChildren, MailboxAttribute::Drafts]),
    ]);
});

$handler->onSelect(static function (SelectEvent $event, ConnectionContext $ctx) use (&$store, $uidValidity, &$nextUid): void {
    $event->accept(new SelectResult(
        exists: count($store),
        recent: 1,
        flags: [new Flag('\\Answered'), new Flag('\\Seen'), new Flag('\\Deleted'), new Flag('\\Draft')],
        permanentFlags: [new Flag('\\Answered'), new Flag('\\Seen'), new Flag('\\Deleted'), new Flag('\\Draft'), new Flag('\\*')],
        uidValidity: $uidValidity,
        uidNext: $nextUid,
        readWrite: true,
    ));
});

$handler->onFetch(static function (FetchEvent $event, ConnectionContext $ctx) use (&$store): void {
    $results = [];
    foreach ($store as $seq => $m) {
        $results[] = new FetchResult(
            sequenceNumber: $seq,
            uid: $m['uid'],
            flags: $m['flags'],
            rfc822Size: $m['size'],
            envelope: $m['envelope'],
            bodySections: ['TEXT' => $m['body']],
        );
    }
    $event->accept($results);
});

$handler->onStore(static function (StoreEvent $event, ConnectionContext $ctx) use (&$store): void {
    $changed = [];
    foreach ($store as $seq => $m) {
        $store[$seq]['flags'] = [new Flag('\\Seen')];
        $changed[] = ['seq' => $seq, 'flags' => $store[$seq]['flags']];
    }
    $event->accept($changed);
});

$handler->onSearch(static function (SearchEvent $event, ConnectionContext $ctx) use (&$store): void {
    $hits = [];
    foreach ($store as $seq => $m) {
        $seen = false;
        foreach ($m['flags'] as $f) {
            if ($f->value === '\\Seen') {
                $seen = true;
            }
        }
        if (!$seen) {
            $hits[] = $seq;
        }
    }
    $event->accept($hits);
});

$handler->onAppend(static function (AppendEvent $event, ConnectionContext $ctx) use (&$store, &$nextUid, $uidValidity): void {
    $store[] = [
        'uid' => $nextUid,
        'flags' => [],
        'envelope' => new Envelope(
            date: 'Tue, 30 Sep 2026 09:00:00 +0300',
            subject: 'Appended message',
            from: [new Address('Interop Client', null, 'client', 'example.com')],
            sender: null,
            replyTo: null,
            to: null,
            cc: null,
            bcc: null,
            inReplyTo: null,
            messageId: '<appended@example.com>',
        ),
        'size' => strlen($event->message()),
        'body' => $event->message(),
    ];
    $event->accept([$uidValidity, $nextUid]);
    $nextUid++;
});

$server = new StreamServer($handler, '127.0.0.1', $port);
$server->enableImplicitTls($certFile, $keyFile);
$server->start();
