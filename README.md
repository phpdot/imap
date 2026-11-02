# phpdot/imap

IMAP4rev1/IMAP4rev2 protocol library for PHP. Client and server.

## Requirements

| Requirement | Constraint |
|---|---|
| PHP | `>=8.5` |
| `ext-mbstring` | `*` |

Development only:  `friendsofphp/php-cs-fixer` `^3.94`,  `phpstan/phpstan` `^2.0`,  `phpstan/phpstan-strict-rules` `^2.0`,  `phpunit/phpunit` `^13.0`.

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

A connection runs one command at a time: bytes that arrive while a handler is
waiting (a coroutine yielding on a database query) are held and answered, in
order, by the call already running — the second `onData()` returns nothing.

On a plaintext listener that offers STARTTLS, call
`$conn->context()->session()->setTlsActive(false)` before the greeting. LOGIN
is then refused until the client upgrades; once `$conn->needsTlsUpgrade()` is
true after an OK, flush that OK, run the TLS handshake on the socket, and call
`$conn->tlsUpgraded()`. Nothing the client sent after STARTTLS and before the
handshake is ever run as a command.

### Several sessions on one mailbox

Sequence numbers are per session. Hand the mailbox's UIDs to `SelectResult`
and the connection keeps that session's sequence map for you
(`$ctx->uidList()`, `$ctx->sequenceToUid()`, `$ctx->uidToSequence()`):

```php
$handler->onSelect(function (SelectEvent $event, ConnectionContext $ctx) {
    $ctx->setChannelScope($ctx->user());          // one account's changes stay with that account
    $event->accept(new SelectResult(exists: 3, uidValidity: 38505, uidNext: 44, uids: [40, 41, 43]));
});
```

Publish what changes and every other session watching the mailbox is told in
its own numbering:

```php
$channel = ConnectionContext::channel($account, 'INBOX');
$notifier->publish($channel, MailboxChange::added($uid));                 // * n EXISTS
$notifier->publish($channel, MailboxChange::expunge($uid), $ctx->connectionId());   // * n EXPUNGE
$notifier->publish($channel, MailboxChange::flagChange($uid, ['\\Seen']), $ctx->connectionId());
```

A client in IDLE receives the change at once. Otherwise it is held and sent
with the session's next command — but never with a sequence-number FETCH,
STORE or SEARCH, whose numbers it would shift (RFC 3501 §7.4.1). With a
custom transport, call `$conn->setPushWriter(fn (string $bytes) => …)` so
IDLE pushes can reach the socket.

For `EXPUNGE`, `UID EXPUNGE` and `MOVE`, return the removed sequence numbers
in the session's pre-expunge numbering; the connection emits them highest
first and updates the map.

### Reading FETCH requests

`$event->fetchItems()` gives each requested item with its section and partial
attached (`BODY.PEEK[HEADER.FIELDS (From)]<0.512>` → name `BODY.PEEK`, section
`HEADER.FIELDS (From)`, offset `0`, count `512`; `isPeek()` tells you whether
to set `\Seen`). `FetchResult` carries `bodySections` with `sectionOrigins`
for partials, `body` for the non-extensible `BODY` item, and `rfc822`,
`rfc822Header`, `rfc822Text`.

### Large results

Accept a FETCH with a generator and nothing is built up front:

```php
$handler->onFetch(function (FetchEvent $event, ConnectionContext $ctx) use ($store) {
    $event->accept((function () use ($event, $store) {
        foreach ($store->messages($event->sequenceSet()) as $seq => $message) {
            yield new FetchResult(sequenceNumber: $seq, bodySections: ['' => $message->read()]);
        }
    })());
});
```

`StreamServer` pulls one result at a time as the socket drains and does not
read the connection's next command until the response is out, so commands
pipelined behind a large FETCH run in order, against the state of their turn.
A custom transport gets the same through `ServerConnection::onDataDeferred()`
(entries are strings or iterators), then `hasBacklog()` / `resume()`;
`onData()` still returns plain strings.

### ESEARCH and CONDSTORE

- `SEARCH RETURN (MIN MAX ALL COUNT)` is answered with `* ESEARCH` built from
  the list your handler returns — no handler change needed.
- CONDSTORE turns on for a session through `ENABLE CONDSTORE`,
  `SELECT … (CONDSTORE)`, `CHANGEDSINCE`, `UNCHANGEDSINCE`, `FETCH MODSEQ`,
  `SEARCH MODSEQ` or `STATUS (HIGHESTMODSEQ)` — when the server advertises it.
  Check `$ctx->session()->isCondstoreEnabled()`.
- Hand mod-sequences back and the engine places them: `SelectResult` /
  `StatusInfo::$highestModseq`, `FetchResult::$modseq`,
  `MailboxChange::flagChange($uid, $flags, $modseq)`, a `'modseq'` key on each
  STORE entry, and for a conditional STORE
  `['changed' => $entries, 'modified' => $uidsOrSeqs]` → `OK [MODIFIED …]`.
- A SEARCH that used MODSEQ returns
  `['results' => $numbers, 'modseqs' => $parallelList]`.

### Limits built into StreamServer

- Commands per connection: token bucket, `maxCommandsPerSecond` (50) refill
  with a `commandBurst` (500) allowance; exceeding it ends the session with BYE.
- `maxConnections` (512), at most `maxConnectionsPerAddress` (32) from one
  address; a connection that has not logged in times out after
  `preAuthTimeout` (60 s), other non-IDLE ones after `connectionTimeout`,
  IDLE ones after `idleTimeout` (35 min — RFC 2177 clients re-issue IDLE
  within 29).
- `enableTls()` offers STARTTLS: sessions start with LOGIN disabled and are
  upgraded in place; `enableImplicitTls()` serves IMAPS.
- Before login, one command may carry at most 8 KB of literals.
- Command lines over 64 KB and literals over `maxLiteralBytes` (50 MB) are
  refused with a tagged BAD — a synchronizing literal before its data is
  sent; literal data does not count against the line limit.
- Only unsolicited pushes are subject to `maxWriteBufferBytes`; command
  responses are paced by backpressure instead of being cut off.
- `onListening()` runs once the socket is bound (drop privileges there);
  `onTick()` runs about once a second on the loop.

## What's Covered

Every command and response from RFC 9051 (IMAP4rev2) and RFC 3501 (IMAP4rev1):

- **40+ commands**: LOGIN, AUTHENTICATE, SELECT, FETCH, SEARCH, SORT, THREAD, STORE, COPY, MOVE, APPEND, EXPUNGE, LIST, LSUB, STATUS, IDLE, ENABLE, NAMESPACE, ID, COMPRESS, QUOTA, ACL, METADATA, all UID variants
- **All data types**: atoms, quoted strings, literals, literal8, NIL, lists, sequence sets, sections, partials
- **All responses**: ENVELOPE, BODYSTRUCTURE, FETCH, ESEARCH, BINARY, APPENDUID, COPYUID, FLAGS, CAPABILITY, 37 response codes
- **Extensions**: CONDSTORE, QRESYNC, COMPRESS, QUOTA, ID, SPECIAL-USE, SORT, THREAD, ACL, METADATA, LIST-EXTENDED, LIST-STATUS

## Quality

- `declare(strict_types=1)` on every file
- PHPStan max level, zero errors, no ignores
- 494 tests, 1352 assertions
- Zero runtime dependencies
- Server: built-in StreamServer or wire to Swoole/ReactPHP/Workerman/Amp

## License

MIT
