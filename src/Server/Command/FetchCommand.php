<?php

declare(strict_types=1);

/**
 * Parsed IMAP FETCH command: sequence set, items, UID flag, CHANGEDSINCE.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Mail\IMAP\Server\Command;

use PHPdot\Mail\IMAP\DataType\DTO\Token;
use PHPdot\Mail\IMAP\DataType\ValueObject\SequenceSet;
use PHPdot\Mail\IMAP\DataType\ValueObject\Tag;

readonly class FetchCommand extends Command
{
    /**
     * @param list<Token> $items Raw parsed FETCH item tokens
     */
    public function __construct(
        Tag $tag,
        public SequenceSet $sequenceSet,
        public array $items,
        public bool $isUid = false,
        public int|null $changedSince = null,
    ) {
        parent::__construct($tag, $isUid ? 'UID FETCH' : 'FETCH');
    }
}
