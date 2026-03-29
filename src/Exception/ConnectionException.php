<?php
/**
 * Exception for IMAP connection failures: refused, timeout, lost.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

declare(strict_types=1);

namespace PHPdot\Mail\IMAP\Exception;

class ConnectionException extends RuntimeException
{
}
