<?php
/**
 * Parsed IMAP ID command: client identification key-value pairs.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

declare(strict_types=1);

namespace PHPdot\Mail\IMAP\Server\Command;

use PHPdot\Mail\IMAP\DataType\ValueObject\Tag;

readonly class IdCommand extends Command
{
    /**
     * @param array<string, string>|null $params Client identification params (null = NIL)
     */
    public function __construct(
        Tag $tag,
        public ?array $params = null,
    ) {
        parent::__construct($tag, 'ID');
    }
}
