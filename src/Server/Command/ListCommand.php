<?php

declare(strict_types=1);

/**
 * Parsed IMAP LIST/LSUB command: reference, pattern, options.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Mail\IMAP\Server\Command;

use PHPdot\Mail\IMAP\DataType\ValueObject\Tag;

readonly class ListCommand extends Command
{
    /**
     * @param list<string> $selectOptions
     * @param list<string> $returnOptions
     */
    public function __construct(
        Tag $tag,
        public string $reference,
        public string $pattern,
        public array $selectOptions = [],
        public array $returnOptions = [],
        public bool $isLsub = false,
    ) {
        parent::__construct($tag, $isLsub ? 'LSUB' : 'LIST');
    }
}
