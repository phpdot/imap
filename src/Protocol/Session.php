<?php
/**
 * Tracks IMAP protocol negotiation state: rev1/rev2, CONDSTORE, UTF8, capabilities.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

declare(strict_types=1);

namespace PHPdot\Mail\IMAP\Protocol;

use PHPdot\Mail\IMAP\DataType\ValueObject\CapabilitySet;
use PHPdot\Mail\IMAP\Protocol\Encoding\ModifiedUtf7;

/**
 * Tracks the negotiated protocol state for a connection.
 *
 * Manages:
 * - Capabilities (what the server/client advertise)
 * - rev1 vs rev2 negotiation
 * - CONDSTORE, UTF8=ACCEPT, COMPRESS state
 * - Mailbox encoding (Modified UTF-7 vs UTF-8)
 */
final class Session
{
    private bool $rev2Enabled = false;
    private bool $condstoreEnabled = false;
    private bool $utf8Enabled = false;
    private bool $compressionEnabled = false;
    private bool $tlsActive = true; // Default: assume TLS (port 993). Set false for cleartext port 143.
    private CapabilitySet $capabilities;

    public function __construct()
    {
        $this->capabilities = new CapabilitySet();
    }

    // === CAPABILITY MANAGEMENT ===

    /**
     * Set the current capabilities (from CAPABILITY response or greeting).
     */
    public function setCapabilities(CapabilitySet $capabilities): void
    {
        $this->capabilities = $capabilities;
    }

    /**
     * Get the current capabilities.
     */
    public function capabilities(): CapabilitySet
    {
        return $this->capabilities;
    }

    /**
     * Check if a capability is available.
     */
    public function hasCapability(string $name): bool
    {
        return $this->capabilities->has($name);
    }

    /**
     * Check if an auth mechanism is available.
     */
    public function hasAuth(string $mechanism): bool
    {
        return $this->capabilities->hasAuth($mechanism);
    }

    /**
     * Clear capabilities (required after STARTTLS — client must re-request).
     */
    public function invalidateCapabilities(): void
    {
        $this->capabilities = new CapabilitySet();
    }

    // === TLS ===

    public function setTlsActive(bool $active = true): void
    {
        $this->tlsActive = $active;
    }

    public function isTlsActive(): bool
    {
        return $this->tlsActive;
    }

    // === ENABLE MANAGEMENT ===

    public function enableRev2(): void
    {
        $this->rev2Enabled = true;
    }

    public function enableCondstore(): void
    {
        $this->condstoreEnabled = true;
    }

    public function enableUtf8(): void
    {
        $this->utf8Enabled = true;
    }

    public function enableCompression(): void
    {
        $this->compressionEnabled = true;
    }

    /**
     * Process an ENABLE command and return which capabilities were actually enabled.
     *
     * @param list<string> $requested
     * @return list<string>
     */
    public function processEnable(array $requested): array
    {
        $enabled = [];

        foreach ($requested as $cap) {
            $upper = strtoupper($cap);
            match ($upper) {
                'IMAP4REV2' => (function () use (&$enabled): void {
                    if (!$this->rev2Enabled) {
                        $this->rev2Enabled = true;
                        $enabled[] = 'IMAP4REV2';
                    }
                })(),
                'CONDSTORE' => (function () use (&$enabled): void {
                    if (!$this->condstoreEnabled) {
                        $this->condstoreEnabled = true;
                        $enabled[] = 'CONDSTORE';
                    }
                })(),
                'UTF8=ACCEPT' => (function () use (&$enabled): void {
                    if (!$this->utf8Enabled) {
                        $this->utf8Enabled = true;
                        $enabled[] = 'UTF8=ACCEPT';
                    }
                })(),
                default => null,
            };
        }

        return $enabled;
    }

    // === QUERY STATE ===

    public function isRev2(): bool
    {
        return $this->rev2Enabled;
    }

    public function isRev1(): bool
    {
        return !$this->rev2Enabled;
    }

    public function isCondstoreEnabled(): bool
    {
        return $this->condstoreEnabled;
    }

    public function isUtf8Enabled(): bool
    {
        return $this->utf8Enabled;
    }

    public function isCompressionEnabled(): bool
    {
        return $this->compressionEnabled;
    }

    // === PROTOCOL-AWARE HELPERS ===

    public function supportsRecent(): bool
    {
        return $this->isRev1();
    }

    public function useEsearch(): bool
    {
        return $this->rev2Enabled;
    }

    public function useUtf7Mailbox(): bool
    {
        return !$this->rev2Enabled && !$this->utf8Enabled;
    }

    public function encodeMailbox(string $mailbox): string
    {
        if ($this->useUtf7Mailbox()) {
            return ModifiedUtf7::encode($mailbox);
        }
        return $mailbox;
    }

    public function decodeMailbox(string $mailbox): string
    {
        if ($this->useUtf7Mailbox()) {
            return ModifiedUtf7::decode($mailbox);
        }
        return $mailbox;
    }

    /**
     * Should LOGIN be disabled? (before TLS on cleartext port)
     */
    public function isLoginDisabled(): bool
    {
        return !$this->tlsActive;
    }
}
