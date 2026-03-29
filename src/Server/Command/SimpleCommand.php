<?php
/**
 * Parsed IMAP command with no arguments beyond tag and name.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

declare(strict_types=1);

namespace PHPdot\Mail\IMAP\Server\Command;

use PHPdot\Mail\IMAP\DataType\ValueObject\Tag;

/**
 * Command with no arguments beyond the tag and name.
 * Used for: CAPABILITY, NOOP, LOGOUT, STARTTLS, CLOSE, UNSELECT, EXPUNGE, CHECK, NAMESPACE.
 */
readonly class SimpleCommand extends Command
{
    public function __construct(Tag $tag, string $name)
    {
        parent::__construct($tag, strtoupper($name));
    }
}
