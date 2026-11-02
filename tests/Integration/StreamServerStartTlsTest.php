<?php

/**
 * End-to-end tests for STARTTLS on the StreamServer's plaintext listener.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

declare(strict_types=1);

namespace PHPdot\Mail\IMAP\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

/**
 * STARTTLS upgrades the socket in place, and nothing sent before the handshake is executed.
 */
final class StreamServerStartTlsTest extends TestCase
{
    #[Test]
    public function loginIsRefusedUntilTheConnectionIsUpgraded(): void
    {
        $this->withServer(function (int $port): void {
            $client = $this->connect($port);
            fgets($client);

            fwrite($client, "a1 LOGIN user pass\r\n");
            self::assertStringStartsWith('a1 BAD', $this->readTagged($client, 'a1'));

            fwrite($client, "a2 STARTTLS\r\n");
            self::assertStringStartsWith('a2 OK', $this->readTagged($client, 'a2'));
            $this->upgrade($client);

            fwrite($client, "a3 LOGIN user pass\r\n");
            self::assertStringStartsWith('a3 OK', $this->readTagged($client, 'a3'));
            fclose($client);
        });
    }

    #[Test]
    public function commandsSentWithStartTlsAreNeverRun(): void
    {
        $this->withServer(function (int $port): void {
            $client = $this->connect($port);
            fgets($client);

            fwrite($client, "a1 STARTTLS\r\na2 NOOP\r\n");
            self::assertStringStartsWith('a1 OK', $this->readTagged($client, 'a1'));
            $this->upgrade($client);

            fwrite($client, "a3 NOOP\r\n");
            $reply = $this->readTagged($client, 'a3');
            self::assertStringNotContainsString('a2 ', $reply);
            self::assertStringStartsWith('a3 OK', $reply);
            fclose($client);
        });
    }

    /**
     * @param callable(int): void $client
     */
    private function withServer(callable $client): void
    {
        $port = random_int(25000, 49999);
        $stderr = tempnam(sys_get_temp_dir(), 'imap-starttls-') ?: throw new RuntimeException('temp file failed');

        $proc = proc_open(
            [
                PHP_BINARY,
                __DIR__ . '/../fixtures/implicit_tls_imap_server.php',
                (string) $port,
                __DIR__ . '/../fixtures/localhost-cert.pem',
                __DIR__ . '/../fixtures/localhost-key.pem',
                'starttls',
            ],
            [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', $stderr, 'w']],
            $pipes,
        );
        self::assertIsResource($proc, 'failed to spawn server process');

        try {
            $client($port);
        } catch (Throwable $failure) {
            throw new RuntimeException($failure->getMessage() . "\n--- fixture stderr ---\n" . file_get_contents($stderr));
        } finally {
            proc_terminate($proc);
            proc_close($proc);
            @unlink($stderr);
        }
    }

    /**
     * @return resource
     */
    private function connect(int $port)
    {
        $context = stream_context_create([
            'ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true],
        ]);

        $errstr = '';
        for ($i = 0; $i < 50; $i++) {
            $client = @stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $errstr, 5.0, STREAM_CLIENT_CONNECT, $context);
            if ($client !== false) {
                stream_set_timeout($client, 10);

                return $client;
            }
            usleep(100_000);
        }

        throw new RuntimeException("STARTTLS IMAP server never came up on port {$port}: {$errstr}");
    }

    /**
     * @param resource $client
     */
    private function upgrade($client): void
    {
        self::assertTrue(stream_socket_enable_crypto($client, true, STREAM_CRYPTO_METHOD_TLS_CLIENT));
    }

    /**
     * @param resource $stream
     */
    private function readTagged($stream, string $tag): string
    {
        $reply = '';
        while (($line = fgets($stream)) !== false) {
            $reply .= $line;
            if (str_starts_with($line, $tag . ' ')) {
                return $reply;
            }
        }

        return $reply;
    }
}
