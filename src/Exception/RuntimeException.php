<?php
/**
 * Runtime exception for IMAP operations.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

declare(strict_types=1);

namespace PHPdot\Mail\IMAP\Exception;

class RuntimeException extends \RuntimeException implements ImapException
{
}
