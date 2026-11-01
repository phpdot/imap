<?php

declare(strict_types=1);

/**
 * Server event for IMAP commands with no extra data.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Mail\IMAP\Server\Event;

/**
 * Event for commands with no extra data beyond the command itself.
 * Used for: StartTls, Capability, Noop, Logout, Close, Unselect, Expunge,
 *           Check, Namespace, Idle, Enable, Create, Delete, Subscribe,
 *           Unsubscribe, Rename, List, Status, Id, Compress, Quota, UidExpunge.
 */
class SimpleEvent extends Event {}
