<?php

declare(strict_types=1);

/**
 * Exception thrown when compiling DTOs to wire format fails.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Mail\IMAP\Exception;

class CompileException extends \RuntimeException implements ImapException {}
