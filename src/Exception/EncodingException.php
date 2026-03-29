<?php
/**
 * Exception thrown when Modified UTF-7 or charset encoding fails.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

declare(strict_types=1);

namespace PHPdot\Mail\IMAP\Exception;

class EncodingException extends \RuntimeException implements ImapException
{
}
