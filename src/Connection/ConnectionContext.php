<?php
/**
 * Per-connection context exposed to server handlers.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

declare(strict_types=1);

namespace PHPdot\Mail\IMAP\Connection;

use PHPdot\Mail\IMAP\Protocol\Session;

/**
 * Tracks per-connection state: authenticated user, selected mailbox,
 * UID list, modseq, and custom data.
 */
final class ConnectionContext
{
    private ?string $user = null;
    private ?string $selectedMailbox = null;
    private string $remoteAddress;
    private readonly string $connectionId;
    private readonly Session $session;
    /** @var list<int> */
    private array $uidList = [];
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

    public function user(): ?string
    {
        return $this->user;
    }

    public function setSelectedMailbox(?string $mailbox): void
    {
        $this->selectedMailbox = $mailbox;
    }

    public function selectedMailbox(): ?string
    {
        return $this->selectedMailbox;
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

    public function sequenceToUid(int $seq): ?int
    {
        return $this->uidList[$seq - 1] ?? null;
    }

    public function uidToSequence(int $uid): ?int
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

    // === SEARCHRES ($ variable) ===

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
