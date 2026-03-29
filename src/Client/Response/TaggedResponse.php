<?php
/**
 * IMAP tagged response: tag, status, response text.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

declare(strict_types=1);

namespace PHPdot\Mail\IMAP\Client\Response;

use PHPdot\Mail\IMAP\DataType\DTO\ResponseText;
use PHPdot\Mail\IMAP\DataType\Enum\ResponseStatus;
use PHPdot\Mail\IMAP\DataType\ValueObject\Tag;

readonly class TaggedResponse extends Response
{
    public function __construct(
        public Tag $tag,
        public ResponseStatus $status,
        public ResponseText $responseText,
    ) {}

    public function isOk(): bool
    {
        return $this->status === ResponseStatus::Ok;
    }

    public function isNo(): bool
    {
        return $this->status === ResponseStatus::No;
    }

    public function isBad(): bool
    {
        return $this->status === ResponseStatus::Bad;
    }
}
