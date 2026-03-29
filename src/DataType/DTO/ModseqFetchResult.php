<?php
/**
 * FETCH result entry with modification sequence for CONDSTORE.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

declare(strict_types=1);

namespace PHPdot\Mail\IMAP\DataType\DTO;

/**
 * Extended FetchResult that includes MODSEQ for CONDSTORE-aware responses.
 * Used when CHANGEDSINCE modifier is applied to FETCH.
 */
readonly class ModseqFetchResult
{
    public function __construct(
        public int $uid,
        public int $modseq,
        public int $sequenceNumber,
    ) {}
}
