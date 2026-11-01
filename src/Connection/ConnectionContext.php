<?php

declare(strict_types=1);

/**
 * Per-connection context exposed to server handlers.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Mail\IMAP\Connection;

use PHPdot\Mail\IMAP\Protocol\Session;

/**
 * Tracks per-connection state: authenticated user, selected mailbox,
 * UID list, modseq, and custom data.
 */
final class ConnectionContext
{
    private string|null $user = null;
    private string|null $selectedMailbox = null;
    private string $channelScope = '';
    private string $remoteAddress;
    private readonly string $connectionId;
    private readonly Session $session;
    /** @var list<int> */
    private array $uidList = [];
    private bool $tracksUids = false;
    private int $modseq = 0;
    /** @var array<string, mixed> */
    private array $data = [];

    public function __construct(string $remoteAddress = '127.0.0.1')
    {
        $this->connectionId = bin2hex(random_bytes(8));
        $this->remoteAddress = $remoteAddress;
        $this->session = new Session();
    }

    public function setUser(string $user): void
    {
        $this->user = $user;
    }

    public function user(): string|null
    {
        return $this->user;
    }

    public function setSelectedMailbox(string|null $mailbox): void
    {
        $this->selectedMailbox = $mailbox;
    }

    public function selectedMailbox(): string|null
    {
        return $this->selectedMailbox;
    }

    /**
     * Scope this session's mailbox-change channel — typically the account.
     *
     * Mailbox names are only unique per account: in a multi-user host every
     * account has an INBOX. With a scope set, the session subscribes to
     * channelFor(mailbox) and the host publishes to the same channel, so one
     * account's changes never reach another's sessions.
     */
    public function setChannelScope(string $scope): void
    {
        $this->channelScope = $scope;
    }

    /**
     * The notifier channel for a mailbox as seen by this session.
     */
    public function channelFor(string $mailbox): string
    {
        return self::channel($this->channelScope, $mailbox);
    }

    /**
     * The notifier channel of a mailbox within a scope — what a host
     * publishes to so that sessions with that scope receive the change.
     */
    public static function channel(string $scope, string $mailbox): string
    {
        return $scope === '' ? $mailbox : $scope . "\x1F" . $mailbox;
    }

    public function session(): Session
    {
        return $this->session;
    }

    public function remoteAddress(): string
    {
        return $this->remoteAddress;
    }

    public function setRemoteAddress(string $address): void
    {
        $this->remoteAddress = $address;
    }

    public function connectionId(): string
    {
        return $this->connectionId;
    }

    /**
     * @param list<int> $uids
     */
    public function setUidList(array $uids): void
    {
        $this->uidList = $uids;
    }

    /**
     * @return list<int>
     */
    public function uidList(): array
    {
        return $this->uidList;
    }

    /**
     * Start (or stop) tracking this session's sequence map. Set by the
     * connection on SELECT when the host supplies SelectResult::$uids.
     *
     * @param list<int>|null $uids UIDs in sequence order, or null to stop tracking
     */
    public function trackUids(array|null $uids): void
    {
        $this->tracksUids = $uids !== null;
        $this->uidList = $uids ?? [];
    }

    /**
     * Whether uidList() is this session's authoritative sequence map.
     */
    public function tracksUids(): bool
    {
        return $this->tracksUids;
    }

    /**
     * Append a new message to the sequence map; returns the new message count.
     * A UID already present is not added twice.
     */
    public function appendUid(int $uid): int
    {
        if (!in_array($uid, $this->uidList, true)) {
            $this->uidList[] = $uid;
        }

        return count($this->uidList);
    }

    /**
     * Remove a message from the sequence map; returns the sequence number it
     * held (the number to report in the untagged EXPUNGE), or null if absent.
     */
    public function removeUid(int $uid): int|null
    {
        $index = array_search($uid, $this->uidList, true);
        if ($index === false) {
            return null;
        }

        array_splice($this->uidList, $index, 1);

        return $index + 1;
    }

    public function sequenceToUid(int $seq): int|null
    {
        return $this->uidList[$seq - 1] ?? null;
    }

    public function uidToSequence(int $uid): int|null
    {
        $index = array_search($uid, $this->uidList, true);
        return $index !== false ? $index + 1 : null;
    }

    public function setModseq(int $modseq): void
    {
        $this->modseq = $modseq;
    }

    public function modseq(): int
    {
        return $this->modseq;
    }

    /** @var list<int> */
    private array $lastSearchResult = [];

    /**
     * Store the result of a SEARCH with SAVE.
     *
     * @param list<int> $uids
     */
    public function setLastSearchResult(array $uids): void
    {
        $this->lastSearchResult = $uids;
    }

    /**
     * @return list<int>
     */
    public function lastSearchResult(): array
    {
        return $this->lastSearchResult;
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }
}
