<?php

declare(strict_types=1);

/**
 * Exception thrown for IMAP protocol violations.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Mail\IMAP\Exception;

class ProtocolException extends \RuntimeException implements ImapException {}
