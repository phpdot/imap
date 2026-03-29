<?php
/**
 * Exception for invalid arguments passed to IMAP components.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

declare(strict_types=1);

namespace PHPdot\Mail\IMAP\Exception;

class InvalidArgumentException extends \InvalidArgumentException implements ImapException
{
}
