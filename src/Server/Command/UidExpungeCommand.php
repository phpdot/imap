<?php
/**
 * Parsed IMAP UID EXPUNGE command: sequence set of UIDs to expunge.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

declare(strict_types=1);

namespace PHPdot\Mail\IMAP\Server\Command;

use PHPdot\Mail\IMAP\DataType\ValueObject\SequenceSet;
use PHPdot\Mail\IMAP\DataType\ValueObject\Tag;

readonly class UidExpungeCommand extends Command
{
    public function __construct(
        Tag $tag,
        public SequenceSet $sequenceSet,
    ) {
        parent::__construct($tag, 'UID EXPUNGE');
    }
}
