<?php
/**
 * Base class for all parsed IMAP server commands.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

declare(strict_types=1);

namespace PHPdot\Mail\IMAP\Server\Command;

use PHPdot\Mail\IMAP\DataType\ValueObject\Tag;

/**
 * Base class for all parsed IMAP commands.
 */
abstract readonly class Command
{
    public function __construct(
        public Tag $tag,
        public string $name,
    ) {}
}
