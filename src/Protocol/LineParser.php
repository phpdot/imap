<?php
/**
 * Splits incoming byte streams into complete IMAP lines with literal handling.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

declare(strict_types=1);

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

    /** Max literal size in bytes. 0 = unlimited. Default 50MB. */
    private int $maxLiteralSize;

    /** Max command line length in bytes. 0 = unlimited. Default 64KB. */
    private int $maxLineLength;

    public function __construct(int $maxLiteralSize = 52428800, int $maxLineLength = 65536)
    {
        $this->maxLiteralSize = $maxLiteralSize;
        $this->maxLineLength = $maxLineLength;
    }

    /**
     * Feed raw bytes and yield complete lines.
     *
     * @return list<string>
     */
    public function feed(string $data): array
    {
        $this->buffer .= $data;
        $lines = [];

        while ($this->buffer !== '') {
            if ($this->inLiteral) {
                $available = strlen($this->buffer);
                if ($available >= $this->literalRemaining) {
                    $this->accumulated .= substr($this->buffer, 0, $this->literalRemaining);
                    $this->buffer = substr($this->buffer, $this->literalRemaining);
                    $this->literalRemaining = 0;
                    $this->inLiteral = false;
                    // Continue reading the rest of the line after the literal
                } else {
                    $this->accumulated .= $this->buffer;
                    $this->literalRemaining -= $available;
                    $this->buffer = '';
                    break;
                }
            }

            // Look for CRLF
            $crlfPos = strpos($this->buffer, "\r\n");
            if ($crlfPos === false) {
                // Check for bare LF
                $lfPos = strpos($this->buffer, "\n");
                if ($lfPos !== false) {
                    $crlfPos = $lfPos;
                    $crlfLen = 1;
                } else {
                    break; // Need more data
                }
            } else {
                $crlfLen = 2;
            }

            $line = substr($this->buffer, 0, $crlfPos);
            $this->buffer = substr($this->buffer, $crlfPos + $crlfLen);

            // Check line length limit
            if ($this->maxLineLength > 0 && strlen($this->accumulated . $line) > $this->maxLineLength) {
                $this->accumulated = '';
                $this->literalRejected = true;
                continue;
            }

            // Check if line ends with a literal marker {N} or {N+}
            if (preg_match('/\{(\d+)(\+)?\}\s*$/', $line, $matches) === 1) {
                $litSize = (int) $matches[1];

                // Reject oversized literals
                if ($this->maxLiteralSize > 0 && $litSize > $this->maxLiteralSize) {
                    $this->literalRejected = true;
                    // Still need to consume the bytes to keep the stream in sync
                    $this->literalPlus = isset($matches[2]);
                    $this->accumulated .= $line . "\r\n";
                    $this->literalRemaining = $litSize;
                    $this->inLiteral = true;
                    continue;
                }

                $this->literalPlus = isset($matches[2]);
                $this->accumulated .= $line . "\r\n";
                $this->literalRemaining = $litSize;
                $this->inLiteral = true;
                continue;
            }

            $this->accumulated .= $line;

            // If literal was rejected, drop the accumulated line
            if ($this->literalRejected) {
                $this->literalRejected = false;
                $this->accumulated = '';
                continue;
            }

            $lines[] = $this->accumulated;
            $this->accumulated = '';
        }

        return $lines;
    }

    /**
     * Returns whether the last literal was rejected due to size limits.
     */
    public function wasLiteralRejected(): bool
    {
        return $this->literalRejected;
    }

    /**
     * Returns whether a continuation response (+) should be sent to the client.
     * This is true when a synchronizing literal {N} (without +) was received.
     */
    public function needsContinuation(): bool
    {
        return $this->inLiteral && !$this->literalPlus;
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
    }
}
