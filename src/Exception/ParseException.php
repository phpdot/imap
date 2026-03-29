<?php
/**
 * Exception thrown when IMAP wire format parsing fails.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

declare(strict_types=1);

namespace PHPdot\Mail\IMAP\Exception;

class ParseException extends \RuntimeException implements ImapException
{
    public function __construct(
        string $message,
        public readonly ParseErrorCode $errorCode,
        public readonly int $position = 0,
        public readonly string $input = '',
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            sprintf('%s at position %d [%s]', $message, $position, $errorCode->value),
            0,
            $previous,
        );
    }
}
