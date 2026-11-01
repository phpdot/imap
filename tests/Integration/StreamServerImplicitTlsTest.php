<?php

/**
 * End-to-end tests for the implicit-TLS (IMAPS) listener.
 *
 * Boots the real StreamServer in a child process with a self-signed
 * certificate and talks to it over ssl:// — the deployment shape production
 * uses: encrypted from the first byte, no plaintext mode.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

declare(strict_types=1);

namespace PHPdot\Mail\IMAP\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Implicit TLS (IMAPS) behavior.
 */
final class StreamServerImplicitTlsTest extends TestCase
{
    private static string $certFile = '';

    private static string $keyFile = '';

    public static function setUpBeforeClass(): void
    {
        self::$certFile = __DIR__ . '/../fixtures/localhost-cert.pem';
        self::$keyFile = __DIR__ . '/../fixtures/localhost-key.pem';
    }

    #[Test]
    public function greetingArrivesOverImplicitTls(): void
    {
        $this->withServer(function (int $port): void {
            $client = $this->connectTls($port);

            $greeting = fgets($client);
            self::assertIsString($greeting);
            self::assertStringStartsWith('* OK', $greeting);

            fwrite($client, "a1 LOGOUT\r\n");
            fclose($client);
        });
    }

    #[Test]
    public function starttlsIsRejectedAndLoginWorksOverTls(): void
    {
        $this->withServer(function (int $port): void {
            $client = $this->connectTls($port);
            fgets($client); // greeting

            // STARTTLS on an already-encrypted connection is a protocol
            // violation (RFC 9051 §6.2.1) — the session is TLS from birth.
            fwrite($client, "a1 STARTTLS\r\n");
            $response = $this->readTagged($client, 'a1');
            self::assertStringContainsString('BAD', $response);
            self::assertStringContainsString('TLS already active', $response);

            // LOGIN is available immediately — the session is TLS-active.
            fwrite($client, "a2 LOGIN user pass\r\n");
            $response = $this->readTagged($client, 'a2');
            self::assertStringContainsString('OK', $response);

            fwrite($client, "a3 LOGOUT\r\n");
            $this->readTagged($client, 'a3');
            fclose($client);
        });
    }

    #[Test]
    public function plaintextClientNeverReceivesAGreeting(): void
    {
        $this->withServer(function (int $port): void {
            $client = $this->connectPlain($port);

            stream_set_timeout($client, 2);
            fwrite($client, "a1 NOOP\r\n");

            $data = (string) fread($client, 1024);
            self::assertStringStartsNotWith('* OK', $data, 'server must not speak IMAP in plaintext');
            fclose($client);
        });
    }

    /**
     * Boot the fixture server on a random port, run the client, then kill it.
     *
     * @param callable(int): void $client
     */
    private function withServer(callable $client): void
    {
        $port = random_int(25000, 49999);
        $stdout = tempnam(sys_get_temp_dir(), 'imaps-out-') ?: throw new RuntimeException('temp file failed');
        $stderr = tempnam(sys_get_temp_dir(), 'imaps-err-') ?: throw new RuntimeException('temp file failed');

        $proc = proc_open(
            [PHP_BINARY, __DIR__ . '/../fixtures/implicit_tls_imap_server.php', (string) $port, self::$certFile, self::$keyFile],
            [
                0 => ['pipe', 'r'],
                1 => ['file', $stdout, 'w'],
                2 => ['file', $stderr, 'w'],
            ],
            $pipes,
        );
        self::assertIsResource($proc, 'failed to spawn server process');

        try {
            $client($port);
        } finally {
            proc_terminate($proc);
            proc_close($proc);
            @unlink($stdout);
            @unlink($stderr);
        }
    }

    /**
     * Connect over TLS, retrying until the child server has bound its port.
     *
     * @return resource
     */
    private function connectTls(int $port)
    {
        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true,
            ],
        ]);

        $errstr = '';
        for ($i = 0; $i < 50; $i++) {
            $client = @stream_socket_client("ssl://127.0.0.1:{$port}", $errno, $errstr, 5.0, STREAM_CLIENT_CONNECT, $context);
            if ($client !== false) {
                stream_set_blocking($client, true);
                return $client;
            }
            usleep(100_000);
        }

        throw new RuntimeException("implicit-TLS IMAP server never came up on port {$port}: {$errstr}");
    }

    /**
     * Connect over plain TCP, retrying until the child server has bound its port.
     *
     * @return resource
     */
    private function connectPlain(int $port)
    {
        $errstr = '';
        for ($i = 0; $i < 50; $i++) {
            $client = @stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $errstr, 2.0);
            if ($client !== false) {
                return $client;
            }
            usleep(100_000);
        }

        throw new RuntimeException("implicit-TLS IMAP server never came up on port {$port}: {$errstr}");
    }

    /**
     * Read lines until the tagged response for $tag arrives.
     *
     * @param resource $stream
     */
    private function readTagged($stream, string $tag): string
    {
        $reply = '';
        $prefix = $tag . ' ';
        while (($line = fgets($stream)) !== false) {
            $reply .= $line;
            if (str_starts_with($line, $prefix)) {
                return $reply;
            }
        }

        return $reply;
    }
}
