<?php
declare(strict_types=1);

namespace PHPdot\Mail\IMAP\Server;

/**
 * Contract for IMAP server implementations.
 *
 * Implement this with Swoole, Workerman, Amp, ReactPHP, or use the
 * built-in StreamServer for zero-dependency operation.
 */
interface ServerInterface
{
    public function start(): void;

    public function shutdown(): void;
}
