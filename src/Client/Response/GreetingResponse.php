<?php
/**
 * Parsed IMAP server greeting: OK, PREAUTH, or BYE.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

declare(strict_types=1);

namespace PHPdot\Mail\IMAP\Client\Response;

use PHPdot\Mail\IMAP\DataType\DTO\ResponseText;
use PHPdot\Mail\IMAP\DataType\Enum\GreetingStatus;

readonly class GreetingResponse extends Response
{
    public function __construct(
        public GreetingStatus $status,
        public ResponseText $responseText,
    ) {}

    public function isOk(): bool
    {
        return $this->status === GreetingStatus::Ok;
    }

    public function isPreAuth(): bool
    {
        return $this->status === GreetingStatus::PreAuth;
    }

    public function isBye(): bool
    {
        return $this->status === GreetingStatus::Bye;
    }
}
