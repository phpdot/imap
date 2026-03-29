<?php
/**
 * Modified UTF-7 encoding/decoding for IMAP4rev1 mailbox names.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

declare(strict_types=1);

namespace PHPdot\Mail\IMAP\Protocol\Encoding;

use PHPdot\Mail\IMAP\Exception\EncodingException;

/**
 * Modified UTF-7 encoding for IMAP4rev1 mailbox names (RFC 3501 Section 5.1.3).
 *
 * Differences from standard UTF-7:
 * - '&' is the shift character (not '+')
 * - ',' is used instead of '/' in base64 alphabet
 * - Printable ASCII (0x20-0x7E) except '&' is literal
 * - '&-' encodes a literal '&'
 */
final class ModifiedUtf7
{
    /**
     * Encode a UTF-8 string to modified UTF-7 for IMAP.
     */
    public static function encode(string $utf8): string
    {
        $result = '';
        $nonAsciiBuffer = '';
        $len = mb_strlen($utf8, 'UTF-8');

        for ($i = 0; $i < $len; $i++) {
            $char = mb_substr($utf8, $i, 1, 'UTF-8');
            $ord = mb_ord($char, 'UTF-8');

            if ($ord >= 0x20 && $ord <= 0x7E) {
                // Flush non-ASCII buffer
                if ($nonAsciiBuffer !== '') {
                    $result .= '&' . self::encodeBase64($nonAsciiBuffer) . '-';
                    $nonAsciiBuffer = '';
                }

                if ($char === '&') {
                    $result .= '&-';
                } else {
                    $result .= $char;
                }
            } else {
                // Accumulate non-ASCII as UTF-16BE
                $nonAsciiBuffer .= self::charToUtf16BE($ord);
            }
        }

        // Flush remaining
        if ($nonAsciiBuffer !== '') {
            $result .= '&' . self::encodeBase64($nonAsciiBuffer) . '-';
        }

        return $result;
    }

    /**
     * Decode a modified UTF-7 IMAP mailbox name to UTF-8.
     */
    public static function decode(string $encoded): string
    {
        $result = '';
        $len = strlen($encoded);
        $pos = 0;

        while ($pos < $len) {
            if ($encoded[$pos] === '&') {
                $pos++;
                if ($pos < $len && $encoded[$pos] === '-') {
                    $result .= '&';
                    $pos++;
                } else {
                    // Find the closing '-'
                    $endPos = strpos($encoded, '-', $pos);
                    if ($endPos === false) {
                        throw new EncodingException(
                            'Unterminated modified base64 sequence in modified UTF-7',
                        );
                    }
                    $base64Str = substr($encoded, $pos, $endPos - $pos);
                    $utf16 = self::decodeBase64($base64Str);
                    $result .= self::utf16BEToUtf8($utf16);
                    $pos = $endPos + 1;
                }
            } else {
                $result .= $encoded[$pos];
                $pos++;
            }
        }

        return $result;
    }

    private static function encodeBase64(string $data): string
    {
        $encoded = base64_encode($data);
        // Remove padding '='
        $encoded = rtrim($encoded, '=');
        // Replace '/' with ','
        return str_replace('/', ',', $encoded);
    }

    private static function decodeBase64(string $data): string
    {
        // Replace ',' with '/'
        $data = str_replace(',', '/', $data);
        // Add padding
        $remainder = strlen($data) % 4;
        if ($remainder > 0) {
            $data .= str_repeat('=', 4 - $remainder);
        }
        $decoded = base64_decode($data, true);
        if ($decoded === false) {
            throw new EncodingException('Invalid modified base64 in modified UTF-7');
        }
        return $decoded;
    }

    private static function charToUtf16BE(int $codepoint): string
    {
        if ($codepoint < 0x10000) {
            return pack('n', $codepoint);
        }

        // Surrogate pair for supplementary plane
        $codepoint -= 0x10000;
        $high = 0xD800 | ($codepoint >> 10);
        $low = 0xDC00 | ($codepoint & 0x3FF);
        return pack('nn', $high, $low);
    }

    private static function utf16BEToUtf8(string $utf16): string
    {
        $result = '';
        $len = strlen($utf16);

        for ($i = 0; $i + 1 < $len; $i += 2) {
            $code = (ord($utf16[$i]) << 8) | ord($utf16[$i + 1]);

            // High surrogate
            if ($code >= 0xD800 && $code <= 0xDBFF) {
                if ($i + 3 >= $len) {
                    throw new EncodingException('Incomplete surrogate pair in UTF-16');
                }
                $low = (ord($utf16[$i + 2]) << 8) | ord($utf16[$i + 3]);
                if ($low < 0xDC00 || $low > 0xDFFF) {
                    throw new EncodingException('Invalid low surrogate in UTF-16');
                }
                $code = 0x10000 + (($code - 0xD800) << 10) + ($low - 0xDC00);
                $i += 2;
            }

            $result .= mb_chr($code, 'UTF-8');
        }

        return $result;
    }
}
