<?php

declare(strict_types=1);

/**
 * Default IMAP server using PHP streams. Zero dependencies.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Mail\IMAP\Server;

use PHPdot\Mail\IMAP\Connection\InMemoryNotifier;
use PHPdot\Mail\IMAP\Connection\NotifierInterface;
use PHPdot\Mail\IMAP\Connection\ServerConnection;
use PHPdot\Mail\IMAP\ImapHandler;

/**
 * Built-in multi-connection IMAP server using stream_socket_server + stream_select.
 *
 * Handles concurrent connections, IDLE-aware I/O, and idle reaping.
 * For production at scale, implement ServerInterface with Swoole or Workerman instead.
 */
final class StreamServer implements ServerInterface
{
    /** @var resource|null */
    private $socket = null;

    /** @var array<int, resource> */
    private array $clients = [];

    /** @var array<int, ServerConnection> */
    private array $connections = [];

    /** Same-process mailbox-change pub/sub — hosts publish new mail here. */
    private readonly InMemoryNotifier $notifier;

    /** @var array<int, float> */
    private array $lastActivity = [];

    /**
     * Responses not yet handed to the socket buffer, per connection — strings,
     * or iterators producing a large result one chunk at a time.
     *
     * @var array<int, list<string|\Iterator<mixed, string>>>
     */
    private array $outQueues = [];

    /** Stop pulling queued output into a connection's buffer above this size. */
    private const WRITE_HIGH_WATER = 1048576;

    /** @var array<int, true> Connections to close as soon as their write buffer drains. */
    private array $closing = [];

    /** @var array<int, array{tokens: float, at: float}> Per-connection command token buckets (rate limiting). */
    private array $commandBuckets = [];

    /** @var list<\Closure(): void> Run once, after the listener is bound and before the loop. */
    private array $listeningCallbacks = [];

    /** @var list<\Closure(): void> Run about once a second from the loop. */
    private array $tickCallbacks = [];

    private float $lastTick = 0.0;

    private bool $running = false;

    private string $tlsCertFile = '';
    private string $tlsKeyFile = '';

    /** Implicit TLS (IMAPS): listener binds ssl://, sessions are encrypted from the first byte. */
    private bool $implicitTls = false;

    /** @var array<int, resource> Streams mid-TLS-handshake keyed by (int) resource id (implicit TLS mode). */
    private array $handshaking = [];

    /** @var array<int, string> Outbound write buffers keyed by id — flushed when the socket is writable. */
    private array $writeBuffers = [];

    /** @var array<int, true> Connections that accepted STARTTLS, read no further until their OK is flushed. */
    private array $upgrading = [];

    /** @var array<int, string> The address each connection came from, for the per-address cap. */
    private array $addresses = [];

    public function __construct(
        private readonly ImapHandler $handler,
        private readonly string $host = '0.0.0.0',
        private readonly int $port = 143,
        private readonly string $hostname = 'localhost',
        private readonly int $connectionTimeout = 1800,
        private readonly \PHPdot\Mail\IMAP\DataType\ValueObject\CapabilitySet|null $greetingCapabilities = null,
        private readonly \Closure|null $wireLogger = null,
        private readonly int $maxWriteBufferBytes = 8388608,
        private readonly int $maxCommandsPerSecond = 50,
        private readonly int $commandBurst = 500,
        private readonly int $maxConnections = 512,
        private readonly int $idleTimeout = 2100,
        private readonly int $maxLiteralBytes = 52428800,
        private readonly int $preAuthTimeout = 60,
        private readonly int $maxConnectionsPerAddress = 32,
    ) {
        $this->notifier = new InMemoryNotifier();
    }

    /**
     * The mailbox-change bus — hosts publish here so IDLE clients get
     * untagged EXISTS pushes (e.g. after appending new mail).
     */
    public function notifier(): NotifierInterface
    {
        return $this->notifier;
    }

    /**
     * Run a callback once the listener is bound, before any client is
     * served — the place to drop root privileges after binding a low port.
     */
    public function onListening(\Closure $callback): void
    {
        $this->listeningCallbacks[] = $callback;
    }

    /**
     * Run a callback about once a second from the event loop — for hosts
     * that must notice external events (mail delivered by another process)
     * and publish them to the notifier. Keep it fast: it runs on the loop.
     */
    public function onTick(\Closure $callback): void
    {
        $this->tickCallbacks[] = $callback;
    }

    /**
     * Enable STARTTLS with a PEM certificate and key: sessions start in plaintext with LOGIN disabled until the
     * client upgrades, and nothing it sent after STARTTLS but before the handshake is ever read as a command.
     */
    public function enableTls(string $certFile, string $keyFile): void
    {
        $this->tlsCertFile = $certFile;
        $this->tlsKeyFile = $keyFile;
    }

    /**
     * Enable implicit TLS (IMAPS, port 993): the listener binds ssl:// and the
     * TLS handshake runs before the IMAP greeting is sent. The session is
     * TLS-active from birth — STARTTLS is rejected as a protocol violation.
     */
    public function enableImplicitTls(string $certFile, string $keyFile): void
    {
        $this->tlsCertFile = $certFile;
        $this->tlsKeyFile = $keyFile;
        $this->implicitTls = true;
    }

    public function start(): void
    {
        if ($this->implicitTls && ($this->tlsCertFile === '' || $this->tlsKeyFile === '')) {
            throw new \RuntimeException('Implicit TLS requires a certificate and key.');
        }

        $contextOptions = ['socket' => ['backlog' => 128]];

        if ($this->tlsCertFile !== '') {
            $contextOptions['ssl'] = [
                'local_cert' => $this->tlsCertFile,
                'local_pk' => $this->tlsKeyFile,
                'allow_self_signed' => true,
                'verify_peer' => false,
                'verify_peer_name' => false,
            ];
        }

        $context = stream_context_create($contextOptions);
        $address = 'tcp://' . $this->host . ':' . $this->port;

        $socket = @stream_socket_server(
            $address,
            $errno,
            $errstr,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
            $context,
        );

        if ($socket === false) {
            throw new \RuntimeException(
                sprintf('Failed to bind to %s: %s (%d)', $address, $errstr, $errno),
            );
        }

        stream_set_blocking($socket, false);
        $this->socket = $socket;
        $this->running = true;

        foreach ($this->listeningCallbacks as $callback) {
            $callback();
        }

        $this->loop();
    }

    public function shutdown(): void
    {
        $this->running = false;
    }

    private function loop(): void
    {
        $socket = $this->socket;
        if ($socket === null) {
            return;
        }

        while ($this->running) {
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }

            $read = array_diff_key($this->clients, $this->outQueues, $this->upgrading);
            $read[] = $socket;

            $write = $this->writeBuffers === []
                ? null
                : array_values(array_intersect_key($this->clients, $this->writeBuffers));
            $except = null;

            $ready = @stream_select($read, $write, $except, 1);
            if ($ready === false) {

                continue;
            }

            $this->tick();

            if ($ready === 0) {
                $this->reapIdleConnections();
                continue;
            }

            foreach ($read as $stream) {
                if ($stream === $socket) {
                    $this->acceptClient($socket);
                } else {
                    $this->readClient($stream);
                }
            }

            foreach ($write ?? [] as $stream) {
                $this->flushWrites($stream);
            }

            $this->reapIdleConnections();
        }

        $this->cleanup();
    }

    /**
     * @param resource $socket
     */
    private function acceptClient($socket): void
    {
        $client = @stream_socket_accept($socket, 0);
        if ($client === false) {
            return;
        }

        if ($this->maxConnections > 0 && count($this->clients) >= $this->maxConnections) {
            @fclose($client);
            return;
        }

        $address = $this->extractIp($client);
        if ($this->maxConnectionsPerAddress > 0
            && count(array_keys($this->addresses, $address, true)) >= $this->maxConnectionsPerAddress) {
            @fclose($client);
            return;
        }

        stream_set_blocking($client, false);
        stream_set_timeout($client, 300);

        $id = (int) $client;
        $this->clients[$id] = $client;
        $this->addresses[$id] = $address;
        $this->lastActivity[$id] = microtime(true);

        if ($this->implicitTls) {
            $this->handshaking[$id] = $client;
            return;
        }

        $this->initializeConnection($client);
    }

    /**
     * Create the protocol connection and send the greeting.
     *
     * @param resource $client
     */
    private function initializeConnection($client): void
    {
        $id = (int) $client;
        $remoteAddr = $this->extractIp($client);

        $conn = new ServerConnection($this->handler, $this->notifier, $remoteAddr, $this->maxLiteralBytes);
        if (!$this->implicitTls && $this->tlsCertFile !== '') {
            $conn->context()->session()->setTlsActive(false);
        }
        $conn->setPushWriter(function (string $bytes) use ($client): void {

            $this->writeToClient($client, $bytes, true);
        });

        $this->connections[$id] = $conn;

        $greeting = $conn->greeting($this->hostname . ' IMAP server ready', $this->greetingCapabilities);
        $this->writeToClient($client, $greeting);
    }

    /**
     * Drive the TLS handshake of an implicitly-encrypted connection.
     *
     * @param resource $stream
     */
    private function driveTlsHandshake($stream, int $id): void
    {
        $result = @stream_socket_enable_crypto(
            $stream,
            true,
            STREAM_CRYPTO_METHOD_TLSv1_2_SERVER | STREAM_CRYPTO_METHOD_TLSv1_3_SERVER,
        );

        if ($result === true) {
            unset($this->handshaking[$id]);
            $conn = $this->connections[$id] ?? null;
            if ($conn !== null) {
                $conn->tlsUpgraded();
            } else {
                $this->initializeConnection($stream);
            }
            return;
        }

        if ($result === 0) {
            return;
        }

        $this->removeClient($id);
    }

    /**
     * @param resource $stream
     */
    private function readClient($stream): void
    {
        $id = (int) $stream;

        if (isset($this->handshaking[$id])) {
            $this->driveTlsHandshake($stream, $id);
            return;
        }

        $conn = $this->connections[$id] ?? null;
        if ($conn === null || isset($this->closing[$id])) {
            $this->removeClient($id);
            return;
        }

        $data = @fread($stream, 8192);

        if ($data === false || ($data === '' && feof($stream))) {
            $conn->onClose();
            $this->removeClient($id);
            return;
        }
        if ($data === '') {
            return;
        }

        $this->logWire($id, 'C>', $data);

        $this->lastActivity[$id] = microtime(true);

        $before = $conn->linesProcessed();
        $responses = $conn->onDataDeferred($data);

        if ($responses !== []) {
            $this->outQueues[$id] = [...($this->outQueues[$id] ?? []), ...$responses];
            $this->pump($id);
        }

        if (!isset($this->clients[$id])) {
            return;
        }

        if (!$this->withinCommandRate($id, $conn->linesProcessed() - $before)) {
            $this->writeToClient($stream, "* BYE Command rate limit exceeded\r\n");
            $this->closeAfterFlush($id);
            return;
        }

        if ($conn->isLoggedOut()) {
            $this->closeAfterFlush($id);
            return;
        }

        if ($conn->needsTlsUpgrade()) {
            $this->upgrading[$id] = true;
            $this->upgradeWhenFlushed($id);
        }
    }

    /**
     * Start the STARTTLS handshake once the tagged OK has left the buffer: the next bytes the client sends are TLS.
     */
    private function upgradeWhenFlushed(int $id): void
    {
        if (!isset($this->upgrading[$id], $this->clients[$id]) || isset($this->writeBuffers[$id]) || isset($this->outQueues[$id])) {
            return;
        }

        unset($this->upgrading[$id]);
        $this->handshaking[$id] = $this->clients[$id];
        $this->driveTlsHandshake($this->clients[$id], $id);
    }

    /**
     * Move queued responses into the connection's write buffer until the
     * buffer is full enough; the rest follows as the socket drains.
     *
     * This is what bounds memory for large results: a lazily produced FETCH
     * is pulled one message at a time, only as fast as the client reads.
     */
    private function pump(int $id): void
    {
        while (isset($this->outQueues[$id], $this->clients[$id])) {
            if (strlen($this->writeBuffers[$id] ?? '') >= self::WRITE_HIGH_WATER) {
                return;
            }

            $head = $this->outQueues[$id][0] ?? null;
            if ($head === null) {

                $conn = $this->connections[$id] ?? null;
                if ($conn !== null && $conn->hasBacklog()) {
                    $this->outQueues[$id] = $conn->resume();
                    if ($conn->isLoggedOut()) {
                        $this->closing[$id] = true;
                    }
                    continue;
                }

                unset($this->outQueues[$id]);
                return;
            }

            if (is_string($head)) {
                array_shift($this->outQueues[$id]);
                $this->writeToClient($this->clients[$id], $head);
                continue;
            }

            try {
                if (!$head->valid()) {
                    array_shift($this->outQueues[$id]);
                    continue;
                }
                $chunk = $head->current();
                $head->next();
            } catch (\Throwable) {

                unset($this->outQueues[$id]);
                $this->writeToClient($this->clients[$id], "* BYE Internal server error\r\n");
                $this->closeAfterFlush($id);
                return;
            }

            $this->writeToClient($this->clients[$id], $chunk);
        }
    }

    /**
     * Close a connection once its outbound buffer has drained, so the final
     * BYE / tagged OK is not lost.
     */
    private function closeAfterFlush(int $id): void
    {
        if (!isset($this->writeBuffers[$id]) && !isset($this->outQueues[$id])) {
            $this->removeClient($id);
            return;
        }

        $this->closing[$id] = true;
    }

    /**
     * Command rate limit: a pathological or hostile client must not be able
     * to monopolize the single-process select loop.
     *
     * Token bucket per connection, counted in COMMANDS (not socket reads —
     * a large APPEND is many reads but one command, a pipelined batch is one
     * read but many commands). The bucket holds $commandBurst commands and
     * refills at $maxCommandsPerSecond, so a client's initial sync burst
     * passes while a sustained flood earns a BYE.
     */
    private function withinCommandRate(int $id, int $commands): bool
    {
        if ($this->maxCommandsPerSecond <= 0 || $commands <= 0) {
            return true;
        }

        $now = microtime(true);
        $capacity = (float) max($this->commandBurst, $this->maxCommandsPerSecond);
        $bucket = $this->commandBuckets[$id] ?? ['tokens' => $capacity, 'at' => $now];

        $tokens = min($capacity, $bucket['tokens'] + ($now - $bucket['at']) * $this->maxCommandsPerSecond);
        $tokens -= $commands;
        $this->commandBuckets[$id] = ['tokens' => $tokens, 'at' => $now];

        return $tokens >= 0.0;
    }

    private function tick(): void
    {
        if ($this->tickCallbacks === []) {
            return;
        }

        $now = microtime(true);
        if (($now - $this->lastTick) < 1.0) {
            return;
        }
        $this->lastTick = $now;

        foreach ($this->tickCallbacks as $callback) {
            $callback();
        }
    }

    /**
     * Write data to a client, buffering whatever the socket cannot take now.
     *
     * On a non-blocking stream fwrite returns 0 (not false) when the send
     * buffer is full — silently dropping the remainder would truncate IMAP
     * literals mid-promise and deadlock the client. The remainder is buffered
     * and flushed by the select loop when the socket becomes writable.
     *
     * @param resource $stream
     */
    private function writeToClient($stream, string $data, bool $unsolicited = false): void
    {
        $id = (int) $stream;
        $this->logWire($id, 'S>', $data);
        $pending = ($this->writeBuffers[$id] ?? '') . $data;

        if ($unsolicited && strlen($pending) > $this->maxWriteBufferBytes) {
            $this->removeClient($id);
            return;
        }

        $written = @fwrite($stream, $pending);
        if ($written === false || $written === 0) {
            if ($written === false) {
                $this->removeClient($id);
                return;
            }
        }

        $remaining = substr($pending, $written);
        if ($remaining === '') {
            unset($this->writeBuffers[$id]);
        } else {
            $this->writeBuffers[$id] = $remaining;
        }
    }

    /**
     * Flush a connection's buffered outbound data.
     *
     * @param resource $stream
     */
    private function flushWrites($stream): void
    {
        $id = (int) $stream;
        $pending = $this->writeBuffers[$id] ?? '';
        if ($pending === '') {
            unset($this->writeBuffers[$id]);
            return;
        }

        $written = @fwrite($stream, $pending);
        if ($written === false) {
            $this->removeClient($id);
            return;
        }

        $remaining = substr($pending, $written);
        if ($remaining === '') {
            unset($this->writeBuffers[$id]);
        } else {
            $this->writeBuffers[$id] = $remaining;
        }

        $this->pump($id);
        if (isset($this->closing[$id]) && !isset($this->writeBuffers[$id]) && !isset($this->outQueues[$id])) {
            $this->removeClient($id);
            return;
        }

        $this->upgradeWhenFlushed($id);
    }

    private function reapIdleConnections(): void
    {
        $now = microtime(true);
        $timeout = (float) $this->connectionTimeout;

        foreach ($this->lastActivity as $id => $lastTime) {

            $conn = $this->connections[$id] ?? null;
            $limit = match (true) {
                $conn === null, !$conn->isAuthenticated() => (float) $this->preAuthTimeout,
                $conn->isIdling() => (float) $this->idleTimeout,
                default => $timeout,
            };

            if (($now - $lastTime) > $limit) {
                if (isset($this->connections[$id])) {
                    $this->connections[$id]->onClose();
                }
                $this->removeClient($id);
            }
        }
    }

    private function removeClient(int $id): void
    {

        if (isset($this->connections[$id])) {
            $this->connections[$id]->onClose();
        }

        if (isset($this->clients[$id])) {
            @fclose($this->clients[$id]);
            unset($this->clients[$id]);
        }
        unset($this->connections[$id], $this->lastActivity[$id], $this->handshaking[$id], $this->writeBuffers[$id], $this->commandBuckets[$id], $this->closing[$id], $this->outQueues[$id], $this->upgrading[$id], $this->addresses[$id]);
    }

    private function cleanup(): void
    {
        foreach ($this->connections as $conn) {
            $conn->onClose();
        }

        foreach ($this->clients as $client) {
            @fclose($client);
        }

        $this->clients = [];
        $this->connections = [];
        $this->lastActivity = [];
        $this->handshaking = [];
        $this->writeBuffers = [];
        $this->outQueues = [];
        $this->upgrading = [];
        $this->addresses = [];

        if ($this->socket !== null) {
            @fclose($this->socket);
            $this->socket = null;
        }
    }

    /**
     * @param resource $client
     */
    private function extractIp($client): string
    {
        $name = stream_socket_get_name($client, true);
        if ($name === false) {
            return '127.0.0.1';
        }

        if (str_starts_with($name, '[')) {
            $end = strpos($name, ']');
            return $end !== false ? substr($name, 1, $end - 1) : $name;
        }

        $colonPos = strrpos($name, ':');
        return $colonPos !== false ? substr($name, 0, $colonPos) : $name;
    }

    /**
     * Forward wire bytes to the injected logger, if any.
     */
    private function logWire(int $id, string $direction, string $bytes): void
    {
        if ($this->wireLogger !== null) {
            ($this->wireLogger)($id, $direction, $bytes);
        }
    }
}
