<?php

declare(strict_types=1);

/**
 * Aggregated response data from IMAP SELECT/EXAMINE command.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Mail\IMAP\Result;

use PHPdot\Mail\IMAP\DataType\ValueObject\Flag;

readonly class SelectResult
{
    /**
     * @param list<Flag> $flags
     * @param list<Flag> $permanentFlags
     * @param list<int>|null $uids UIDs of the mailbox in sequence order (ascending). When given,
     *                             the connection keeps a per-session sequence map
     *                             (ConnectionContext::uidList()) and translates expunge / flag /
     *                             new-mail notifications from other sessions into this
     *                             session's own sequence numbers.
     */
    public function __construct(
        public int $exists,
        public int $recent = 0,
        public array $flags = [],
        public array $permanentFlags = [],
        public int $uidValidity = 0,
        public int $uidNext = 0,
        public int|null $highestModseq = null,
        public bool $readWrite = true,
        public array|null $uids = null,
    ) {}
}
