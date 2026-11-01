<?php

/**
 * Regression: nested multipart BODYSTRUCTURE must render — the recursive
 * compiler once passed unwrapped Part|Multipart children to bodyStructure()
 * and crashed with a TypeError on every real-world message shape.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

declare(strict_types=1);

namespace PHPdot\Mail\IMAP\Tests\Unit\Protocol;

use PHPdot\Mail\IMAP\DataType\DTO\BodyDisposition;
use PHPdot\Mail\IMAP\DataType\DTO\BodyFieldParams;
use PHPdot\Mail\IMAP\DataType\DTO\BodyStructure;
use PHPdot\Mail\IMAP\DataType\DTO\BodyStructureMultipart;
use PHPdot\Mail\IMAP\DataType\DTO\BodyStructurePart;
use PHPdot\Mail\IMAP\DataType\DTO\FetchResult;
use PHPdot\Mail\IMAP\DataType\Enum\ContentEncoding;
use PHPdot\Mail\IMAP\Protocol\WireFormat;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WireFormatNestedStructureTest extends TestCase
{
    #[Test]
    public function rendersNestedMultipartBodystructure(): void
    {
        // mixed(alternative(text/plain, related(text/html, image/png)))
        $structure = new BodyStructure(
            body: new BodyStructureMultipart(
                parts: [
                    new BodyStructureMultipart(
                        parts: [
                            new BodyStructurePart(
                                type: 'text',
                                subtype: 'plain',
                                params: new BodyFieldParams(['charset' => 'utf-8']),
                                encoding: ContentEncoding::SevenBit,
                                size: 68,
                                lines: 0,
                            ),
                            new BodyStructureMultipart(
                                parts: [
                                    new BodyStructurePart(
                                        type: 'text',
                                        subtype: 'html',
                                        params: new BodyFieldParams(['charset' => 'utf-8']),
                                        encoding: ContentEncoding::SevenBit,
                                        size: 170,
                                        lines: 3,
                                    ),
                                    new BodyStructurePart(
                                        type: 'image',
                                        subtype: 'png',
                                        id: '<pr-badge>',
                                        encoding: ContentEncoding::Base64,
                                        size: 96,
                                        disposition: new BodyDisposition(
                                            type: 'inline',
                                            params: new BodyFieldParams(['filename' => 'badge.png']),
                                        ),
                                    ),
                                ],
                                subtype: 'related',
                            ),
                        ],
                        subtype: 'alternative',
                    ),
                ],
                subtype: 'mixed',
            ),
        );

        $response = WireFormat::fetchResponse(new FetchResult(
            sequenceNumber: 13,
            bodyStructure: $structure,
        ));

        self::assertStringStartsWith('* 13 FETCH (BODYSTRUCTURE (', $response);
        self::assertStringContainsString('("TEXT" "PLAIN" ("charset" "utf-8")', $response);
        self::assertStringContainsString('"IMAGE" "PNG" NIL "<pr-badge>"', $response);
        self::assertStringContainsString('("INLINE" ("filename" "badge.png"))', $response);
        self::assertStringContainsString('"RELATED"', $response);
        self::assertStringContainsString('"ALTERNATIVE"', $response);
        // MIXED subtype followed by its extension NILs closes the structure.
        self::assertStringEndsWith('"MIXED" NIL NIL NIL NIL))' . "\r\n", $response);
    }

    #[Test]
    public function rendersSinglepartBodystructure(): void
    {
        $response = WireFormat::fetchResponse(new FetchResult(
            sequenceNumber: 1,
            bodyStructure: new BodyStructure(
                body: new BodyStructurePart(
                    type: 'text',
                    subtype: 'plain',
                    encoding: ContentEncoding::SevenBit,
                    size: 42,
                    lines: 2,
                ),
            ),
        ));

        self::assertStringContainsString('BODYSTRUCTURE ("TEXT" "PLAIN"', $response);
    }
}
