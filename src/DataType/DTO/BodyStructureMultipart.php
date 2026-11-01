<?php

declare(strict_types=1);

/**
 * Multipart MIME node in IMAP BODYSTRUCTURE response.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Mail\IMAP\DataType\DTO;

readonly class BodyStructureMultipart
{
    /**
     * @param list<BodyStructurePart|BodyStructureMultipart> $parts
     * @param list<string>|null $language
     */
    public function __construct(
        public array $parts,
        public string $subtype,
        public BodyFieldParams|null $params = null,
        public BodyDisposition|null $disposition = null,
        public array|string|null $language = null,
        public string|null $location = null,
    ) {}

    public function isAlternative(): bool
    {
        return strcasecmp($this->subtype, 'ALTERNATIVE') === 0;
    }

    public function isMixed(): bool
    {
        return strcasecmp($this->subtype, 'MIXED') === 0;
    }

    public function isRelated(): bool
    {
        return strcasecmp($this->subtype, 'RELATED') === 0;
    }
}
