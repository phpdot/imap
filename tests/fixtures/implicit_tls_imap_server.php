<?php

/**
 * Child-process fixture: boots a StreamServer with implicit TLS (IMAPS).
 *
 * Usage: php implicit_tls_imap_server.php <port> <cert.pem> <key.pem>
 *
 * Accepts exactly one credential pair (user/pass).
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
use PHPdot\Mail\IMAP\ImapHandler;
use PHPdot\Mail\IMAP\Server\Event\LoginEvent;
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
    fwrite(STDERR, "usage: php implicit_tls_imap_server.php <port> <cert.pem> <key.pem>\n");
    exit(1);
}

$handler = new ImapHandler();

$handler->onLogin(static function (LoginEvent $event, ConnectionContext $ctx): void {
    if ($event->username() === 'user' && $event->password() === 'pass') {
        $event->accept();
        return;
    }
    $event->reject('Invalid credentials');
});

$server = new StreamServer(
    $handler,
    '127.0.0.1',
    (int) $port,
);
$server->enableImplicitTls($certFile, $keyFile);
$server->start();
