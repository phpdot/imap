<?php
/**
 * COPYUID response code data: uidvalidity, source UIDs, destination UIDs.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

declare(strict_types=1);

namespace PHPdot\Mail\IMAP\DataType\DTO;

use PHPdot\Mail\IMAP\DataType\ValueObject\SequenceSet;

readonly class CopyUid
{
    public function __construct(
        public int $uidValidity,
        public SequenceSet $sourceUids,
        public SequenceSet $destUids,
    ) {}
}
