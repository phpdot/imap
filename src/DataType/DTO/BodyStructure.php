<?php

declare(strict_types=1);

/**
 * IMAP BODYSTRUCTURE: single part or multipart MIME tree.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Mail\IMAP\DataType\DTO;

readonly class BodyStructure
{
    public function __construct(
        public BodyStructurePart|BodyStructureMultipart $body,
    ) {}

    public function isPart(): bool
    {
        return $this->body instanceof BodyStructurePart;
    }

    public function isMultipart(): bool
    {
        return $this->body instanceof BodyStructureMultipart;
    }

    public function part(): BodyStructurePart|null
    {
        return $this->body instanceof BodyStructurePart ? $this->body : null;
    }

    public function multipart(): BodyStructureMultipart|null
    {
        return $this->body instanceof BodyStructureMultipart ? $this->body : null;
    }

    /**
     * The wrapped node, whichever kind it is — never null (the constructor
     * requires exactly one).
     */
    public function node(): BodyStructurePart|BodyStructureMultipart
    {
        return $this->body;
    }
}
