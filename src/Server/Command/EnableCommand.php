<?php
/**
 * Parsed IMAP ENABLE command: list of capabilities to enable.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

declare(strict_types=1);

namespace PHPdot\Mail\IMAP\Server\Command;

use PHPdot\Mail\IMAP\DataType\ValueObject\Tag;

readonly class EnableCommand extends Command
{
    /**
     * @param list<string> $capabilities
     */
    public function __construct(
        Tag $tag,
        public array $capabilities,
    ) {
        parent::__construct($tag, 'ENABLE');
    }
}
