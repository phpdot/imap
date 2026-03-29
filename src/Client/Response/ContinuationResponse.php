<?php
/**
 * Parsed IMAP continuation request (+).
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

declare(strict_types=1);

namespace PHPdot\Mail\IMAP\Client\Response;

readonly class ContinuationResponse extends Response
{
    public function __construct(
        public string $text = '',
    ) {}
}
