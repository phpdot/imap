<?php
/**
 * Parsed IMAP STORE command: sequence set, operation, flags.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

declare(strict_types=1);

namespace PHPdot\Mail\IMAP\Server\Command;

use PHPdot\Mail\IMAP\DataType\Enum\StoreOperation;
use PHPdot\Mail\IMAP\DataType\ValueObject\Flag;
use PHPdot\Mail\IMAP\DataType\ValueObject\SequenceSet;
use PHPdot\Mail\IMAP\DataType\ValueObject\Tag;

readonly class StoreCommand extends Command
{
    /**
     * @param list<Flag> $flags
     */
    public function __construct(
        Tag $tag,
        public SequenceSet $sequenceSet,
        public StoreOperation $operation,
        public array $flags,
        public bool $isUid = false,
        public ?int $unchangedSince = null,
    ) {
        parent::__construct($tag, $isUid ? 'UID STORE' : 'STORE');
    }
}
