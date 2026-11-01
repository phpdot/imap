<?php

declare(strict_types=1);

/**
 * Runtime exception for IMAP operations.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Mail\IMAP\Exception;

class RuntimeException extends \RuntimeException implements ImapException {}
