<?php

declare(strict_types=1);

/**
 * Exception for IMAP connection failures: refused, timeout, lost.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Mail\IMAP\Exception;

class ConnectionException extends RuntimeException {}
