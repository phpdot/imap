<?php

declare(strict_types=1);

/**
 * IMAP FETCH response: aggregated message attributes.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Mail\IMAP\DataType\DTO;

use PHPdot\Mail\IMAP\DataType\ValueObject\Flag;
use PHPdot\Mail\IMAP\DataType\ValueObject\ImapDateTime;

readonly class FetchResult
{
    /**
     * @param list<Flag>|null $flags
     * @param array<int|string, string|null> $bodySections Section spec => content, emitted as BODY[spec]
     *                                                     (PHP turns a numeric spec like "1" into an int key)
     * @param array<int|string, int> $sectionOrigins Section spec => origin octet, for partial fetches: BODY[spec]<origin>
     * @param BodyStructure|null $body Non-extensible structure, emitted as BODY (the bare BODY item)
     * @param string|null $rfc822 Emitted as RFC822 (whole message)
     * @param string|null $rfc822Header Emitted as RFC822.HEADER
     * @param string|null $rfc822Text Emitted as RFC822.TEXT
     */
    public function __construct(
        public int $sequenceNumber,
        public int|null $uid = null,
        public Envelope|null $envelope = null,
        public BodyStructure|null $bodyStructure = null,
        public array|null $flags = null,
        public ImapDateTime|null $internalDate = null,
        public int|null $rfc822Size = null,
        public array $bodySections = [],
        public int|null $modseq = null,
        public array $sectionOrigins = [],
        public BodyStructure|null $body = null,
        public string|null $rfc822 = null,
        public string|null $rfc822Header = null,
        public string|null $rfc822Text = null,
    ) {}
}
