<?php
/**
 * Base class for IMAP server events with accept/reject pattern.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

declare(strict_types=1);

namespace PHPdot\Mail\IMAP\Server\Event;

use PHPdot\Mail\IMAP\DataType\Enum\ResponseCode;
use PHPdot\Mail\IMAP\DataType\Enum\ResponseStatus;
use PHPdot\Mail\IMAP\Exception\RuntimeException;
use PHPdot\Mail\IMAP\Server\Command\Command;

/**
 * Base class for all server-side IMAP events.
 *
 * Each event carries its corresponding Command DTO and provides
 * accept()/reject() methods for the consumer to indicate the response.
 */
abstract class Event
{
    private ?bool $accepted = null;
    private mixed $result = null;
    private ResponseStatus $rejectStatus = ResponseStatus::No;
    private ?ResponseCode $rejectCode = null;
    private string $rejectText = '';

    public function __construct(
        public readonly Command $command,
    ) {}

    public function accept(mixed $data = null): void
    {
        if ($this->accepted !== null) {
            throw new RuntimeException('Event already handled');
        }
        $this->accepted = true;
        $this->result = $data;
    }

    public function reject(
        string $text,
        ResponseStatus $status = ResponseStatus::No,
        ?ResponseCode $code = null,
    ): void {
        if ($this->accepted !== null) {
            throw new RuntimeException('Event already handled');
        }
        $this->accepted = false;
        $this->rejectStatus = $status;
        $this->rejectCode = $code;
        $this->rejectText = $text;
    }

    public function isHandled(): bool
    {
        return $this->accepted !== null;
    }

    public function isAccepted(): bool
    {
        return $this->accepted === true;
    }

    public function getResult(): mixed
    {
        return $this->result;
    }

    public function getRejectStatus(): ResponseStatus
    {
        return $this->rejectStatus;
    }

    public function getRejectCode(): ?ResponseCode
    {
        return $this->rejectCode;
    }

    public function getRejectText(): string
    {
        return $this->rejectText;
    }
}
