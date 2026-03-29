<?php
/**
 * Parsed IMAP RENAME command: source and destination mailbox.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

declare(strict_types=1);

namespace PHPdot\Mail\IMAP\Server\Command;

use PHPdot\Mail\IMAP\DataType\ValueObject\Mailbox;
use PHPdot\Mail\IMAP\DataType\ValueObject\Tag;

readonly class RenameCommand extends Command
{
    public function __construct(
        Tag $tag,
        public Mailbox $from,
        public Mailbox $to,
    ) {
        parent::__construct($tag, 'RENAME');
    }
}
