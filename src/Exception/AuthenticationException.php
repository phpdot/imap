<?php

declare(strict_types=1);

/**
 * Exception thrown when IMAP LOGIN or AUTHENTICATE fails.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Mail\IMAP\Exception;

class AuthenticationException extends RuntimeException {}
