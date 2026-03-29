<?php
/**
 * Exception thrown when a command is issued in an invalid connection state.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

declare(strict_types=1);

namespace PHPdot\Mail\IMAP\Exception;

class StateException extends \LogicException implements ImapException
{
}
