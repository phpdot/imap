<?php
/**
 * Builds IMAP ENVELOPE DTO from parsed MIME headers.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

declare(strict_types=1);

namespace PHPdot\Mail\IMAP\DataType\Builder;

use PHPdot\Mail\IMAP\DataType\DTO\Address;
use PHPdot\Mail\IMAP\DataType\DTO\Envelope;

/**
 * Converts parsed MIME headers into an IMAP ENVELOPE DTO.
 *
 * The ENVELOPE format is defined in RFC 9051 Section 7.5.2:
 * (date subject from sender reply-to to cc bcc in-reply-to message-id)
 */
final class EnvelopeBuilder
{
    /**
     * Build an Envelope from parsed headers.
     *
     * @param array<string, string|list<string>> $headers Case-insensitive header name → value
     */
    public function build(array $headers): Envelope
    {
        return new Envelope(
            date: $this->getHeader($headers, 'Date'),
            subject: $this->getHeader($headers, 'Subject'),
            from: $this->parseAddressList($this->getHeader($headers, 'From')),
            sender: $this->parseAddressList($this->getHeader($headers, 'Sender'))
                ?? $this->parseAddressList($this->getHeader($headers, 'From')),
            replyTo: $this->parseAddressList($this->getHeader($headers, 'Reply-To'))
                ?? $this->parseAddressList($this->getHeader($headers, 'From')),
            to: $this->parseAddressList($this->getHeader($headers, 'To')),
            cc: $this->parseAddressList($this->getHeader($headers, 'Cc')),
            bcc: $this->parseAddressList($this->getHeader($headers, 'Bcc')),
            inReplyTo: $this->getHeader($headers, 'In-Reply-To'),
            messageId: $this->getHeader($headers, 'Message-ID'),
        );
    }

    /**
     * @param array<string, string|list<string>> $headers
     */
    private function getHeader(array $headers, string $name): ?string
    {
        $lower = strtolower($name);
        foreach ($headers as $key => $value) {
            if (strtolower($key) === $lower) {
                if (is_array($value)) {
                    return implode(', ', $value);
                }
                return $value;
            }
        }
        return null;
    }

    /**
     * @return list<Address>|null
     */
    private function parseAddressList(?string $value): ?array
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $addresses = [];
        $parts = $this->splitAddresses($value);

        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            $addresses[] = $this->parseOneAddress($part);
        }

        return $addresses === [] ? null : $addresses;
    }

    /**
     * @return list<string>
     */
    private function splitAddresses(string $value): array
    {
        $parts = [];
        $current = '';
        $depth = 0;
        $inQuote = false;

        for ($i = 0, $len = strlen($value); $i < $len; $i++) {
            $c = $value[$i];
            if ($c === '"' && ($i === 0 || $value[$i - 1] !== '\\')) {
                $inQuote = !$inQuote;
            }
            if (!$inQuote) {
                if ($c === '(' || $c === '<') {
                    $depth++;
                }
                if ($c === ')' || $c === '>') {
                    $depth--;
                }
                if ($c === ',' && $depth === 0) {
                    $parts[] = $current;
                    $current = '';
                    continue;
                }
            }
            $current .= $c;
        }
        if ($current !== '') {
            $parts[] = $current;
        }

        return $parts;
    }

    private function parseOneAddress(string $raw): Address
    {
        // Format: "Display Name" <user@host> or user@host or Display Name <user@host>
        $name = null;
        $email = $raw;

        // Extract <email>
        if (preg_match('/<([^>]+)>/', $raw, $matches) === 1) {
            $email = $matches[1];
            $namePart = trim(substr($raw, 0, (int) strpos($raw, '<')));
            if ($namePart !== '') {
                $name = trim($namePart, '"\'');
            }
        }

        $email = trim($email);
        $atPos = strpos($email, '@');
        if ($atPos === false) {
            return new Address($name, null, $email !== '' ? $email : null, null);
        }

        $mailbox = substr($email, 0, $atPos);
        $host = substr($email, $atPos + 1);

        return new Address($name, null, $mailbox, $host);
    }
}
