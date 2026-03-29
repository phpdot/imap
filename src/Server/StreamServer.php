<?php
/**
 * Default IMAP server using PHP streams. Zero dependencies.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

declare(strict_types=1);

namespace PHPdot\Mail\IMAP\Server;

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

    /** @var array<int, float> */
    private array $lastActivity = [];

    private bool $running = false;

    private string $tlsCertFile = '';
    private string $tlsKeyFile = '';

    public function __construct(
        private readonly ImapHandler $handler,
        private readonly string $host = '0.0.0.0',
        private readonly int $port = 143,
        private readonly string $hostname = 'localhost',
        private readonly int $connectionTimeout = 1800,
    ) {}

    /**
     * Enable STARTTLS support. Provide paths to PEM-encoded cert and key.
     */
    public function enableTls(string $certFile, string $keyFile): void
    {
        $this->tlsCertFile = $certFile;
        $this->tlsKeyFile = $keyFile;
    }

    public function start(): void
    {
        $contextOptions = ['socket' => ['backlog' => 128]];

        if ($this->tlsCertFile !== '') {
            $contextOptions['ssl'] = [
                'local_cert' => $this->tlsCertFile,
                'local_pk' => $this->tlsKeyFile,
                'allow_self_signed' => true,
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

            $read = $this->clients;
            $read[] = $socket;
            $write = null;
            $except = null;

            $ready = @stream_select($read, $write, $except, 1);
            if ($ready === false) {
                break;
            }

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

        stream_set_blocking($client, false);
        stream_set_timeout($client, 300);

        $id = (int) $client;
        $remoteAddr = $this->extractIp($client);

        $conn = new ServerConnection($this->handler, null, $remoteAddr);

        $this->clients[$id] = $client;
        $this->connections[$id] = $conn;
        $this->lastActivity[$id] = microtime(true);

        $greeting = $conn->greeting($this->hostname . ' IMAP4rev2 Server ready');
        $this->writeToClient($client, $greeting);
    }

    /**
     * @param resource $stream
     */
    private function readClient($stream): void
    {
        $id = (int) $stream;
        $conn = $this->connections[$id] ?? null;
        if ($conn === null) {
            $this->removeClient($id);
            return;
        }

        $data = @fread($stream, 8192);

        if ($data === false || $data === '') {
            $conn->onClose();
            $this->removeClient($id);
            return;
        }

        $this->lastActivity[$id] = microtime(true);

        $responses = $conn->onData($data);

        foreach ($responses as $response) {
            $this->writeToClient($stream, $response);
        }
    }

    /**
     * @param resource $stream
     */
    private function writeToClient($stream, string $data): void
    {
        $length = strlen($data);
        $written = 0;

        while ($written < $length) {
            $bytes = @fwrite($stream, substr($data, $written));
            if ($bytes === false || $bytes === 0) {
                return;
            }
            $written += $bytes;
        }
    }

    private function reapIdleConnections(): void
    {
        $now = microtime(true);
        $timeout = (float) $this->connectionTimeout;

        foreach ($this->lastActivity as $id => $lastTime) {
            if (isset($this->connections[$id]) && $this->connections[$id]->isIdling()) {
                continue;
            }

            if (($now - $lastTime) > $timeout) {
                if (isset($this->connections[$id])) {
                    $this->connections[$id]->onClose();
                }
                $this->removeClient($id);
            }
        }
    }

    private function removeClient(int $id): void
    {
        if (isset($this->clients[$id])) {
            @fclose($this->clients[$id]);
            unset($this->clients[$id]);
        }
        unset($this->connections[$id], $this->lastActivity[$id]);
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
}
