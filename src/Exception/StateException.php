<?php

declare(strict_types=1);

/**
 * Exception thrown when a command is issued in an invalid connection state.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Mail\IMAP\Exception;

class StateException extends \LogicException implements ImapException {}
