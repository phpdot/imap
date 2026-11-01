<?php

declare(strict_types=1);

/**
 * Builds IMAP BODYSTRUCTURE DTO from MIME part data.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Mail\IMAP\DataType\Builder;

use PHPdot\Mail\IMAP\DataType\DTO\BodyDisposition;
use PHPdot\Mail\IMAP\DataType\DTO\BodyFieldParams;
use PHPdot\Mail\IMAP\DataType\DTO\BodyStructure;
use PHPdot\Mail\IMAP\DataType\DTO\BodyStructureMultipart;
use PHPdot\Mail\IMAP\DataType\DTO\BodyStructurePart;
use PHPdot\Mail\IMAP\DataType\DTO\Envelope;
use PHPdot\Mail\IMAP\DataType\Enum\ContentEncoding;

/**
 * Builds IMAP BODYSTRUCTURE DTOs from MIME part data.
 *
 * This builder creates the structured representation that IMAP FETCH BODYSTRUCTURE returns.
 */
final class BodyStructureBuilder
{
    /**
     * Build a single-part body structure.
     *
     * @param array<string, string> $params Content-Type parameters
     * @param list<string>|null $language
     */
    public function buildPart(
        string $type,
        string $subtype,
        array $params = [],
        string|null $id = null,
        string|null $description = null,
        string $encoding = '7BIT',
        int $size = 0,
        int|null $lines = null,
        string|null $md5 = null,
        BodyDisposition|null $disposition = null,
        array|string|null $language = null,
        string|null $location = null,
        Envelope|null $envelope = null,
        BodyStructure|null $bodyStructure = null,
    ): BodyStructure {
        $part = new BodyStructurePart(
            type: strtoupper($type),
            subtype: strtoupper($subtype),
            params: $params !== [] ? new BodyFieldParams($params) : null,
            id: $id,
            description: $description,
            encoding: ContentEncoding::tryFromCaseInsensitive($encoding) ?? ContentEncoding::SevenBit,
            size: $size,
            lines: $lines,
            md5: $md5,
            disposition: $disposition,
            language: $language,
            location: $location,
            envelope: $envelope,
            bodyStructure: $bodyStructure,
        );

        return new BodyStructure($part);
    }

    /**
     * Build a multipart body structure.
     *
     * @param list<BodyStructure> $parts
     * @param array<string, string> $params Content-Type parameters
     * @param list<string>|null $language
     */
    public function buildMultipart(
        array $parts,
        string $subtype,
        array $params = [],
        BodyDisposition|null $disposition = null,
        array|string|null $language = null,
        string|null $location = null,
    ): BodyStructure {
        $multipart = new BodyStructureMultipart(
            parts: array_map(
                static fn(BodyStructure $b): \PHPdot\Mail\IMAP\DataType\DTO\BodyStructurePart|\PHPdot\Mail\IMAP\DataType\DTO\BodyStructureMultipart => $b->node(),
                $parts,
            ),
            subtype: strtoupper($subtype),
            params: $params !== [] ? new BodyFieldParams($params) : null,
            disposition: $disposition,
            language: $language,
            location: $location,
        );

        return new BodyStructure($multipart);
    }

    /**
     * Build a disposition DTO.
     *
     * @param array<string, string> $params
     */
    public function buildDisposition(string $type, array $params = []): BodyDisposition
    {
        return new BodyDisposition(
            type: strtoupper($type),
            params: $params !== [] ? new BodyFieldParams($params) : null,
        );
    }
}
