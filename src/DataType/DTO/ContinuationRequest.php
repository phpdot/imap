<?php
/**
 * IMAP continuation request (+) data.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

declare(strict_types=1);

namespace PHPdot\Mail\IMAP\DataType\DTO;

readonly class ContinuationRequest
{
    public function __construct(
        public string $text = '',
        public ?string $base64 = null,
    ) {}

    public function isBase64(): bool
    {
        return $this->base64 !== null;
    }
}
