<?php
/**
 * Parsed IMAP SELECT/EXAMINE command: mailbox, read-only flag, CONDSTORE.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

declare(strict_types=1);

namespace PHPdot\Mail\IMAP\Server\Command;

use PHPdot\Mail\IMAP\DataType\ValueObject\Mailbox;
use PHPdot\Mail\IMAP\DataType\ValueObject\Tag;

readonly class SelectCommand extends Command
{
    public function __construct(
        Tag $tag,
        public Mailbox $mailbox,
        public bool $readOnly = false,
        public bool $condstore = false,
    ) {
        parent::__construct($tag, $readOnly ? 'EXAMINE' : 'SELECT');
    }
}
