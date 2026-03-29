<?php
/**
 * Parsed IMAP COPY/MOVE command: sequence set, destination mailbox.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

declare(strict_types=1);

namespace PHPdot\Mail\IMAP\Server\Command;

use PHPdot\Mail\IMAP\DataType\ValueObject\Mailbox;
use PHPdot\Mail\IMAP\DataType\ValueObject\SequenceSet;
use PHPdot\Mail\IMAP\DataType\ValueObject\Tag;

/**
 * Used for: COPY, UID COPY, MOVE, UID MOVE.
 */
readonly class CopyMoveCommand extends Command
{
    public function __construct(
        Tag $tag,
        string $name,
        public SequenceSet $sequenceSet,
        public Mailbox $destination,
        public bool $isUid = false,
    ) {
        parent::__construct($tag, $isUid ? 'UID ' . strtoupper($name) : strtoupper($name));
    }
}
