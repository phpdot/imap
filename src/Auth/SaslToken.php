<?php
/**
 * SASL token builders for XOAUTH2 and OAUTHBEARER authentication.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

declare(strict_types=1);

namespace PHPdot\Mail\IMAP\Auth;

/**
 * Builds SASL authentication tokens for OAuth2 mechanisms.
 */
final class SaslToken
{
    /**
     * Build XOAUTH2 token for Gmail and other Google services.
     *
     * Format: base64("user={email}\x01auth=Bearer {token}\x01\x01")
     * Mechanism: AUTHENTICATE XOAUTH2
     */
    public static function xoauth2(string $email, string $accessToken): string
    {
        return base64_encode(
            "user=" . $email . "\x01auth=Bearer " . $accessToken . "\x01\x01",
        );
    }

    /**
     * Build OAUTHBEARER token for Microsoft and RFC 7628 compliant servers.
     *
     * Format: base64("n,,\x01auth=Bearer {token}\x01\x01")
     * Mechanism: AUTHENTICATE OAUTHBEARER
     */
    public static function oauthbearer(string $accessToken): string
    {
        return base64_encode(
            "n,,\x01auth=Bearer " . $accessToken . "\x01\x01",
        );
    }

    /**
     * Build PLAIN token.
     *
     * Format: base64("\x00{username}\x00{password}")
     * Mechanism: AUTHENTICATE PLAIN
     */
    public static function plain(string $username, string $password, string $authzid = ''): string
    {
        return base64_encode(
            $authzid . "\x00" . $username . "\x00" . $password,
        );
    }

    /**
     * Decode a PLAIN token.
     *
     * @return array{authzid: string, username: string, password: string}
     */
    public static function decodePlain(string $base64Token): array
    {
        $decoded = base64_decode($base64Token, true);
        if ($decoded === false) {
            throw new \PHPdot\Mail\IMAP\Exception\InvalidArgumentException('Invalid base64 in PLAIN token');
        }

        $parts = explode("\x00", $decoded);
        if (count($parts) !== 3) {
            throw new \PHPdot\Mail\IMAP\Exception\InvalidArgumentException('Invalid PLAIN token format');
        }

        return [
            'authzid' => $parts[0],
            'username' => $parts[1],
            'password' => $parts[2],
        ];
    }
}
