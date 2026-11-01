<?php

declare(strict_types=1);

/**
 * Parsed IMAP GETQUOTA/GETQUOTAROOT/SETQUOTA command.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Mail\IMAP\Server\Command;

use PHPdot\Mail\IMAP\DataType\ValueObject\Tag;

/**
 * Used for: GETQUOTA, GETQUOTAROOT, SETQUOTA.
 */
readonly class QuotaCommand extends Command
{
    public function __construct(
        Tag $tag,
        string $name,
        public string $root,
    ) {
        parent::__construct($tag, strtoupper($name));
    }
}
