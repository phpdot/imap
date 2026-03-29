<?php
/**
 * Single MIME part in IMAP BODYSTRUCTURE response.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

declare(strict_types=1);

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
        public ?BodyFieldParams $params = null,
        public ?string $id = null,
        public ?string $description = null,
        public ContentEncoding $encoding = ContentEncoding::SevenBit,
        public int $size = 0,
        public ?int $lines = null,
        public ?string $md5 = null,
        public ?BodyDisposition $disposition = null,
        public array|string|null $language = null,
        public ?string $location = null,
        public ?Envelope $envelope = null,
        public ?BodyStructure $bodyStructure = null,
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

    public function charset(): ?string
    {
        return $this->params?->charset();
    }

    public function filename(): ?string
    {
        return $this->disposition?->filename() ?? $this->params?->name();
    }
}
