<?php
/**
 * Parsed IMAP command with a single mailbox argument.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

declare(strict_types=1);

namespace PHPdot\Mail\IMAP\Server\Command;

use PHPdot\Mail\IMAP\DataType\ValueObject\Mailbox;
use PHPdot\Mail\IMAP\DataType\ValueObject\Tag;

/**
 * Command that takes a single mailbox argument.
 * Used for: CREATE, DELETE, SUBSCRIBE, UNSUBSCRIBE.
 */
readonly class MailboxCommand extends Command
{
    public function __construct(
        Tag $tag,
        string $name,
        public Mailbox $mailbox,
    ) {
        parent::__construct($tag, strtoupper($name));
    }
}
