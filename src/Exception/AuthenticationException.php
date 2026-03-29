<?php
/**
 * Exception thrown when IMAP LOGIN or AUTHENTICATE fails.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

declare(strict_types=1);

namespace PHPdot\Mail\IMAP\Exception;

class AuthenticationException extends RuntimeException
{
}
