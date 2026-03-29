<?php
/**
 * IMAP FETCH response: aggregated message attributes.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

declare(strict_types=1);

namespace PHPdot\Mail\IMAP\DataType\DTO;

use PHPdot\Mail\IMAP\DataType\ValueObject\Flag;
use PHPdot\Mail\IMAP\DataType\ValueObject\ImapDateTime;

readonly class FetchResult
{
    /**
     * @param list<Flag>|null $flags
     * @param array<string, string|null> $bodySections
     */
    public function __construct(
        public int $sequenceNumber,
        public ?int $uid = null,
        public ?Envelope $envelope = null,
        public ?BodyStructure $bodyStructure = null,
        public ?array $flags = null,
        public ?ImapDateTime $internalDate = null,
        public ?int $rfc822Size = null,
        public array $bodySections = [],
        public ?int $modseq = null,
    ) {}
}
