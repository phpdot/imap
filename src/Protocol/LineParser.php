<?php

declare(strict_types=1);

/**
 * Splits incoming byte streams into complete IMAP lines with literal handling.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Mail\IMAP\Protocol;

/**
 * Splits incoming byte stream into complete IMAP lines.
 *
 * Handles literal detection: when a line ends with {N}\r\n or {N+}\r\n,
 * switches to byte-counting mode and reads exactly N bytes of literal data,
 * then continues reading the rest of the command line.
 *
 * Usage:
 *   $parser = new LineParser();
 *   foreach ($parser->feed($chunk) as $completeLine) {
 *       // $completeLine is a full IMAP command/response including any embedded literals
 *   }
 */
final class LineParser
{
    private string $buffer = '';
    private int $literalRemaining = 0;
    private bool $inLiteral = false;
    private string $accumulated = '';
    private bool $literalPlus = false;
    private bool $literalRejected = false;

    /** A synchronizing literal was announced and its `+` go-ahead has not been issued yet. */
    private bool $continuationPending = false;

    /** The command being assembled is over a limit: consume it, keep nothing. */
    private bool $discarding = false;

    /** Why the command being discarded was rejected. */
    private string $discardReason = '';

    /** Dropping an over-long line that has not reached its line break yet. */
    private bool $skipToEol = false;

    /** Cumulative literal octets of the command being assembled; stacked literals must not multiply past the cap. */
    private int $commandLiteralBytes = 0;

    /** Octets of the current command outside literals — what the line limit applies to. */
    private int $lineLength = 0;

    /** Tag of the command being assembled, for the rejection response. */
    private string $currentTag = '';

    /** @var list<array{tag: string, reason: string}> */
    private array $rejections = [];

    /** Max literal size in bytes. 0 = unlimited. Default 50MB. */
    private int $maxLiteralSize;

    /** Max command line length in bytes, literals excluded. 0 = unlimited. Default 64KB. */
    private int $maxLineLength;

    public function __construct(int $maxLiteralSize = 52428800, int $maxLineLength = 65536)
    {
        $this->maxLiteralSize = $maxLiteralSize;
        $this->maxLineLength = $maxLineLength;
    }

    /**
     * Sets how many literal bytes one command may carry from the next line on, 0 for no limit.
     */
    public function setMaxLiteralSize(int $bytes): void
    {
        $this->maxLiteralSize = $bytes;
    }

    /**
     * Feed raw bytes and yield complete lines.
     *
     * @return list<string>
     */
    public function feed(string $data): array
    {
        $this->buffer .= $data;
        $this->literalRejected = false;
        $lines = [];

        while ($this->buffer !== '') {
            if ($this->inLiteral) {
                $take = min(strlen($this->buffer), $this->literalRemaining);
                if (!$this->discarding) {
                    $this->accumulated .= substr($this->buffer, 0, $take);
                }
                $this->buffer = substr($this->buffer, $take);
                $this->literalRemaining -= $take;

                if ($this->literalRemaining > 0) {
                    break;
                }

                $this->inLiteral = false;
                continue;
            }

            $crlfPos = strpos($this->buffer, "\r\n");
            if ($crlfPos === false) {

                $lfPos = strpos($this->buffer, "\n");
                if ($lfPos !== false) {
                    $crlfPos = $lfPos;
                    $crlfLen = 1;
                } else {

                    if ($this->maxLineLength > 0 && $this->lineLength + strlen($this->buffer) > $this->maxLineLength) {
                        if ($this->currentTag === '') {
                            $this->currentTag = self::tagOf($this->buffer);
                        }
                        $this->buffer = '';
                        $this->startDiscarding('Command line too long');
                        $this->skipToEol = true;
                    }
                    break;
                }
            } else {
                $crlfLen = 2;
            }

            $line = substr($this->buffer, 0, $crlfPos);
            $this->buffer = substr($this->buffer, $crlfPos + $crlfLen);

            if ($this->skipToEol) {

                $this->skipToEol = false;
                $this->finishRejected();
                continue;
            }

            if ($this->currentTag === '' && $this->accumulated === '') {
                $this->currentTag = self::tagOf($line);
            }

            $this->lineLength += strlen($line);
            if ($this->maxLineLength > 0 && $this->lineLength > $this->maxLineLength) {
                $this->startDiscarding('Command line too long');
            }

            if (preg_match('/\{(\d+)(\+)?\}$/', $line, $matches) === 1) {
                $litSize = (int) $matches[1];
                $this->literalPlus = isset($matches[2]);

                if ($this->maxLiteralSize > 0 && ($litSize > $this->maxLiteralSize || $this->commandLiteralBytes + $litSize > $this->maxLiteralSize)) {
                    $this->startDiscarding('Literal exceeds the maximum size');
                }

                if ($this->discarding && !$this->literalPlus) {

                    $this->finishRejected();
                    continue;
                }

                if (!$this->discarding) {
                    $this->accumulated .= $line . "\r\n";
                    $this->commandLiteralBytes += $litSize;
                }
                $this->literalRemaining = $litSize;
                $this->inLiteral = true;
                $this->continuationPending = !$this->literalPlus;
                continue;
            }

            if ($this->discarding) {
                $this->finishRejected();
                continue;
            }

            $lines[] = $this->accumulated . $line;
            $this->accumulated = '';
            $this->lineLength = 0;
            $this->currentTag = '';
            $this->commandLiteralBytes = 0;
        }

        return $lines;
    }

    /**
     * Commands rejected for exceeding a limit since the last call, with the
     * tag to answer them under ('' when the tag could not be determined).
     *
     * @return list<array{tag: string, reason: string}>
     */
    public function drainRejections(): array
    {
        $rejections = $this->rejections;
        $this->rejections = [];

        return $rejections;
    }

    /**
     * Returns whether a command was rejected due to size limits during the last feed().
     */
    public function wasLiteralRejected(): bool
    {
        return $this->literalRejected;
    }

    private function startDiscarding(string $reason): void
    {
        if (!$this->discarding) {
            $this->discarding = true;
            $this->discardReason = $reason;
            $this->accumulated = '';
        }
    }

    private function finishRejected(): void
    {
        $this->rejections[] = ['tag' => $this->currentTag, 'reason' => $this->discardReason];
        $this->literalRejected = true;
        $this->discarding = false;
        $this->discardReason = '';
        $this->accumulated = '';
        $this->lineLength = 0;
        $this->currentTag = '';
        $this->commandLiteralBytes = 0;
    }

    private static function tagOf(string $line): string
    {
        return preg_match('/^([A-Za-z0-9.\-_]{1,64}) /', $line, $m) === 1 ? $m[1] : '';
    }

    /**
     * Returns whether a continuation response (+) should be sent to the client:
     * true exactly once after a synchronizing literal {N} (without +) was
     * announced and its data is still outstanding.
     */
    public function needsContinuation(): bool
    {

        $pending = $this->continuationPending && $this->inLiteral;
        $this->continuationPending = false;

        return $pending;
    }

    /**
     * Returns whether the parser is waiting for more literal data.
     */
    public function isInLiteral(): bool
    {
        return $this->inLiteral;
    }

    /**
     * Returns the number of literal bytes still expected.
     */
    public function literalBytesRemaining(): int
    {
        return $this->literalRemaining;
    }

    /**
     * Resets the parser state.
     */
    public function reset(): void
    {
        $this->buffer = '';
        $this->literalRemaining = 0;
        $this->inLiteral = false;
        $this->accumulated = '';
        $this->literalPlus = false;
        $this->continuationPending = false;
        $this->discarding = false;
        $this->discardReason = '';
        $this->skipToEol = false;
        $this->lineLength = 0;
        $this->currentTag = '';
        $this->rejections = [];
    }
}
