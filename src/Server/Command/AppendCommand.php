<?php
/**
 * Parsed IMAP APPEND command: mailbox, flags, date, message literal.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

declare(strict_types=1);

namespace PHPdot\Mail\IMAP\Server\Command;

use PHPdot\Mail\IMAP\DataType\ValueObject\Flag;
use PHPdot\Mail\IMAP\DataType\ValueObject\Mailbox;
use PHPdot\Mail\IMAP\DataType\ValueObject\Tag;

readonly class AppendCommand extends Command
{
    /**
     * @param list<Flag> $flags
     */
    public function __construct(
        Tag $tag,
        public Mailbox $mailbox,
        public string $message,
        public array $flags = [],
        public ?string $internalDate = null,
    ) {
        parent::__construct($tag, 'APPEND');
    }
}
