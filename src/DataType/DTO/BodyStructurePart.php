<?php

declare(strict_types=1);

/**
 * Single MIME part in IMAP BODYSTRUCTURE response.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Mail\IMAP\DataType\DTO;

use PHPdot\Mail\IMAP\DataType\Enum\ContentEncoding;

readonly class BodyStructurePart
{
    /**
     * @param list<string>|null $language
     */
    public function __construct(
        public string $type,
        public string $subtype,
        public BodyFieldParams|null $params = null,
        public string|null $id = null,
        public string|null $description = null,
        public ContentEncoding $encoding = ContentEncoding::SevenBit,
        public int $size = 0,
        public int|null $lines = null,
        public string|null $md5 = null,
        public BodyDisposition|null $disposition = null,
        public array|string|null $language = null,
        public string|null $location = null,
        public Envelope|null $envelope = null,
        public BodyStructure|null $bodyStructure = null,
    ) {}

    public function isText(): bool
    {
        return strcasecmp($this->type, 'TEXT') === 0;
    }

    public function isMessage(): bool
    {
        return strcasecmp($this->type, 'MESSAGE') === 0
            && (strcasecmp($this->subtype, 'RFC822') === 0 || strcasecmp($this->subtype, 'GLOBAL') === 0);
    }

    public function isAttachment(): bool
    {
        return $this->disposition?->isAttachment() === true;
    }

    public function contentType(): string
    {
        return strtoupper($this->type) . '/' . strtoupper($this->subtype);
    }

    public function charset(): string|null
    {
        return $this->params?->charset();
    }

    public function filename(): string|null
    {
        return $this->disposition?->filename() ?? $this->params?->name();
    }
}
