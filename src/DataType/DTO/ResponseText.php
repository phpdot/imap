<?php

declare(strict_types=1);

/**
 * IMAP response text with optional response code and data.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Mail\IMAP\DataType\DTO;

use PHPdot\Mail\IMAP\DataType\Enum\ResponseCode;

readonly class ResponseText
{
    /**
     * @param list<string> $codeData
     */
    public function __construct(
        public ResponseCode|null $code = null,
        public array $codeData = [],
        public string $text = '',
    ) {}

    public function hasCode(): bool
    {
        return $this->code !== null;
    }
}
