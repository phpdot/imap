<?php
/**
 * Parsed METADATA commands: GETMETADATA, SETMETADATA (RFC 5464).
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

declare(strict_types=1);

namespace PHPdot\Mail\IMAP\Server\Command;

use PHPdot\Mail\IMAP\DataType\ValueObject\Tag;

readonly class MetadataCommand extends Command
{
    /**
     * @param list<string> $entries Entry names to get or set
     * @param array<string, string|null> $values Entry values for SETMETADATA (null = delete)
     */
    public function __construct(
        Tag $tag,
        string $name,
        public string $mailbox,
        public array $entries = [],
        public array $values = [],
    ) {
        parent::__construct($tag, strtoupper($name));
    }
}
