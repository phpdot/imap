<?php
/**
 * Multipart MIME node in IMAP BODYSTRUCTURE response.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

declare(strict_types=1);

namespace PHPdot\Mail\IMAP\DataType\DTO;

readonly class BodyStructureMultipart
{
    /**
     * @param list<BodyStructure> $parts
     * @param list<string>|null $language
     */
    public function __construct(
        public array $parts,
        public string $subtype,
        public ?BodyFieldParams $params = null,
        public ?BodyDisposition $disposition = null,
        public array|string|null $language = null,
        public ?string $location = null,
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
