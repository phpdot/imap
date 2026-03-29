<?php
/**
 * Aggregated response data from IMAP SELECT/EXAMINE command.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

declare(strict_types=1);

namespace PHPdot\Mail\IMAP\Result;

use PHPdot\Mail\IMAP\DataType\ValueObject\Flag;

readonly class SelectResult
{
    /**
     * @param list<Flag> $flags
     * @param list<Flag> $permanentFlags
     */
    public function __construct(
        public int $exists,
        public int $recent = 0,
        public array $flags = [],
        public array $permanentFlags = [],
        public int $uidValidity = 0,
        public int $uidNext = 0,
        public ?int $highestModseq = null,
        public bool $readWrite = true,
    ) {}
}
