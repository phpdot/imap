# phpdot/imap

IMAP4rev1/IMAP4rev2 protocol library for PHP. Client and server.

## Install

```bash
composer require phpdot/imap
```

Requires PHP 8.2+.

## Client

```php
use PHPdot\Mail\IMAP\ImapClient;

$client = new ImapClient('imap.gmail.com', 993, 'ssl');
$client->connect();
$client->login('user@gmail.com', 'app-password');

$inbox = $client->select('INBOX');
echo $inbox->exists . " messages\n";

$messages = $client->fetch('1:10', ['FLAGS', 'ENVELOPE']);
foreach ($messages as $msg) {
    echo $msg->envelope->subject . "\n";
}

$unseen = $client->search('UNSEEN');
$client->store('1:3', '+FLAGS', ['\\Seen']);

$folders = $client->listMailboxes();
$status = $client->status('INBOX', ['MESSAGES', 'UNSEEN']);

$client->idle(function ($notification) {
    echo $notification->type . "\n";
    return true; // keep listening, return false to stop
});

$client->logout();
```

### Download emails

```php
// Stream — one message at a time, low memory
$client->fetchStream('1:*', ['UID', 'BODY.PEEK[]'], function ($msg) {
    file_put_contents("eml/{$msg->uid}.eml", $msg->bodySections[''] ?? '');
});

// Batch with resume
$client->uidFetchStream("{$lastUid}:*", ['UID', 'BODY.PEEK[]'], function ($msg) {
    file_put_contents("eml/{$msg->uid}.eml", $msg->bodySections[''] ?? '');
});
```

### Extensions

```php
// Server-side sort (RFC 5256)
$sorted = $client->sort('(DATE)', 'UNSEEN');

// Threading (RFC 5256)
$threads = $client->thread('REFERENCES');

// CONDSTORE (RFC 7162)
$client->enable(['CONDSTORE']);

// QRESYNC (RFC 7162)
$client->selectQresync('INBOX', $uidValidity, $modseq);
```

## Server

```php
use PHPdot\Mail\IMAP\ImapHandler;
use PHPdot\Mail\IMAP\Connection\ConnectionContext;
use PHPdot\Mail\IMAP\Result\SelectResult;
use PHPdot\Mail\IMAP\Server\Event\LoginEvent;
use PHPdot\Mail\IMAP\Server\Event\SelectEvent;
use PHPdot\Mail\IMAP\Server\Event\FetchEvent;
use PHPdot\Mail\IMAP\Server\StreamServer;

$handler = new ImapHandler();

$handler->onLogin(function (LoginEvent $event, ConnectionContext $ctx): void {
    if ($event->username() === 'omar' && $event->password() === 'secret') {
        $event->accept();
    } else {
        $event->reject('Invalid credentials');
    }
});

$handler->onSelect(function (SelectEvent $event, ConnectionContext $ctx): void {
    $event->accept(new SelectResult(exists: 172, uidValidity: 38505, uidNext: 4392));
});

$handler->onFetch(function (FetchEvent $event, ConnectionContext $ctx): void {
    // query your storage, return list<FetchResult>
    $event->accept([]);
});

// Run with built-in server
$server = new StreamServer($handler, port: 143);
$server->start();
```

### Wire to any runtime

```php
use PHPdot\Mail\IMAP\Connection\ServerConnection;

// Swoole
$swoole->on('connect', function ($srv, $fd) use ($handler) {
    $conn = new ServerConnection($handler);
    $connections[$fd] = $conn;
    $srv->send($fd, $conn->greeting());
});

$swoole->on('receive', function ($srv, $fd, $r, $data) use (&$connections) {
    foreach ($connections[$fd]->onData($data) as $response) {
        $srv->send($fd, $response);
    }
});

// Workerman, ReactPHP, Amp — same pattern
```

## What's Covered

Every command and response from RFC 9051 (IMAP4rev2) and RFC 3501 (IMAP4rev1):

- **40+ commands**: LOGIN, AUTHENTICATE, SELECT, FETCH, SEARCH, SORT, THREAD, STORE, COPY, MOVE, APPEND, EXPUNGE, LIST, LSUB, STATUS, IDLE, ENABLE, NAMESPACE, ID, COMPRESS, QUOTA, ACL, METADATA, all UID variants
- **All data types**: atoms, quoted strings, literals, literal8, NIL, lists, sequence sets, sections, partials
- **All responses**: ENVELOPE, BODYSTRUCTURE, FETCH, ESEARCH, BINARY, APPENDUID, COPYUID, FLAGS, CAPABILITY, 37 response codes
- **Extensions**: CONDSTORE, QRESYNC, COMPRESS, QUOTA, ID, SPECIAL-USE, SORT, THREAD, ACL, METADATA, LIST-EXTENDED, LIST-STATUS

## Quality

- `declare(strict_types=1)` on every file
- PHPStan max level, zero errors, no ignores
- 457 tests, 1222 assertions
- Zero runtime dependencies
- Server: built-in StreamServer or wire to Swoole/ReactPHP/Workerman/Amp

## License

MIT
