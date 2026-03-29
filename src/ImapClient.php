<?php
/**
 * High-level IMAP client. Connect, login, fetch, search — no protocol details.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

declare(strict_types=1);

namespace PHPdot\Mail\IMAP;

use PHPdot\Mail\IMAP\Client\ClientProtocol;
use PHPdot\Mail\IMAP\Client\Response\ContinuationResponse;
use PHPdot\Mail\IMAP\Client\Response\GreetingResponse;
use PHPdot\Mail\IMAP\Client\Response\TaggedResponse;
use PHPdot\Mail\IMAP\DataType\DTO\FetchResult;
use PHPdot\Mail\IMAP\DataType\DTO\MailboxInfo;
use PHPdot\Mail\IMAP\DataType\DTO\StatusInfo;
use PHPdot\Mail\IMAP\DataType\DTO\Token;
use PHPdot\Mail\IMAP\DataType\Enum\ResponseCode;
use PHPdot\Mail\IMAP\DataType\ValueObject\CapabilitySet;
use PHPdot\Mail\IMAP\DataType\ValueObject\Flag;
use PHPdot\Mail\IMAP\DataType\ValueObject\NamespaceSet;
use PHPdot\Mail\IMAP\DataType\ValueObject\Tag;
use PHPdot\Mail\IMAP\Exception\AuthenticationException;
use PHPdot\Mail\IMAP\Exception\ConnectionException;
use PHPdot\Mail\IMAP\Exception\RuntimeException;
use PHPdot\Mail\IMAP\Protocol\Session;
use PHPdot\Mail\IMAP\Result\IdleNotification;
use PHPdot\Mail\IMAP\Result\SelectResult;

final class ImapClient
{
    private readonly ClientProtocol $protocol;
    /** @var resource|null */
    private $stream = null;
    private int $readTimeout = 300;

    public function __construct(
        private readonly string $host = '',
        private readonly int $port = 993,
        private readonly string $encryption = 'ssl',
    ) {
        $this->protocol = new ClientProtocol();
    }

    /**
     * @param resource $stream
     */
    public static function fromStream($stream): self
    {
        $client = new self();
        $client->stream = $stream;
        stream_set_timeout($stream, $client->readTimeout);
        return $client;
    }

    // === CONNECTION ===

    public function connect(): void
    {
        if ($this->stream === null) {
            $proto = match ($this->encryption) {
                'ssl' => 'ssl', 'tls' => 'tls', default => 'tcp',
            };
            $address = $proto . '://' . $this->host . ':' . $this->port;
            $context = stream_context_create([
                'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
            ]);
            $errno = 0;
            $errstr = '';
            $stream = @stream_socket_client($address, $errno, $errstr, 30, STREAM_CLIENT_CONNECT, $context);
            if ($stream === false) {
                throw new ConnectionException(sprintf('Connection to %s failed: %s (%d)', $address, $errstr, $errno));
            }
            stream_set_timeout($stream, $this->readTimeout);
            $this->stream = $stream;
        }

        $bytes = $this->read();
        if ($bytes === '') {
            throw new ConnectionException('Server closed connection immediately');
        }
        $this->protocol->onData($bytes);
        $greeting = $this->protocol->greeting();
        if ($greeting !== null && $greeting->isBye()) {
            throw new ConnectionException('Server rejected connection: ' . $greeting->responseText->text);
        }
    }

    public function disconnect(): void
    {
        if ($this->stream !== null) {
            @fclose($this->stream);
            $this->stream = null;
        }
    }

    public function isConnected(): bool
    {
        return $this->stream !== null && !feof($this->stream);
    }

    public function reconnect(): void
    {
        $this->disconnect();
        $this->protocol->resetParser();
        $this->stream = null;
        $this->connect();
    }

    public function greeting(): ?GreetingResponse
    {
        return $this->protocol->greeting();
    }

    // === AUTHENTICATION ===

    public function login(string $user, string $pass): void
    {
        [$tag, $bytes] = $this->protocol->command()->login($user, $pass);
        $this->write($bytes);
        $tagged = $this->readUntilTagged($tag);
        if (!$tagged->isOk()) {
            throw new AuthenticationException('Login failed: ' . $tagged->responseText->text);
        }
    }

    public function authenticate(string $mechanism, string $token): void
    {
        [$tag, $bytes] = $this->protocol->command()->authenticate($mechanism, $token);
        $this->write($bytes);
        $tagged = $this->readUntilTaggedOrContinuation($tag);
        if ($tagged === null) {
            $this->write($token . "\r\n");
            $tagged = $this->readUntilTagged($tag);
        }
        if (!$tagged->isOk()) {
            throw new AuthenticationException('Authentication failed: ' . $tagged->responseText->text);
        }
    }

    public function startTls(): void
    {
        [$tag, $bytes] = $this->protocol->command()->startTls();
        $this->write($bytes);
        $tagged = $this->readUntilTagged($tag);
        if (!$tagged->isOk()) {
            throw new RuntimeException('STARTTLS failed: ' . $tagged->responseText->text);
        }
        $this->upgradeToTls();
        $this->protocol->session()->setTlsActive();
        $this->protocol->session()->invalidateCapabilities();
    }

    // === CAPABILITIES ===

    public function capabilities(): CapabilitySet
    {
        if ($this->protocol->session()->capabilities()->count() === 0) {
            [$tag, $bytes] = $this->protocol->command()->capability();
            $this->write($bytes);
            $this->readUntilTagged($tag);
        }
        return $this->protocol->session()->capabilities();
    }

    public function hasCapability(string $name): bool
    {
        return $this->capabilities()->has($name);
    }

    // === MAILBOX ===

    public function select(string $mailbox): SelectResult
    {
        return $this->doSelect($mailbox, false);
    }

    public function examine(string $mailbox): SelectResult
    {
        return $this->doSelect($mailbox, true);
    }

    public function selectQresync(string $mailbox, int $uidValidity, int $modseq, string $knownUids = ''): SelectResult
    {
        $this->protocol->resetSelectState();
        [$tag, $bytes] = $this->protocol->command()->selectQresync($mailbox, $uidValidity, $modseq, $knownUids);
        $this->write($bytes);
        $tagged = $this->readUntilTagged($tag);
        if (!$tagged->isOk()) {
            throw new RuntimeException('SELECT QRESYNC failed: ' . $tagged->responseText->text);
        }
        return $this->buildSelectResult($tagged);
    }

    public function create(string $mailbox): void { $this->executeSimple($this->protocol->command()->create($mailbox), 'CREATE'); }
    public function delete(string $mailbox): void { $this->executeSimple($this->protocol->command()->delete($mailbox), 'DELETE'); }
    public function rename(string $from, string $to): void { $this->executeSimple($this->protocol->command()->rename($from, $to), 'RENAME'); }
    public function subscribe(string $mailbox): void { $this->executeSimple($this->protocol->command()->subscribe($mailbox), 'SUBSCRIBE'); }
    public function unsubscribe(string $mailbox): void { $this->executeSimple($this->protocol->command()->unsubscribe($mailbox), 'UNSUBSCRIBE'); }
    public function close(): void { $this->executeSimple($this->protocol->command()->close(), 'CLOSE'); }
    public function unselect(): void { $this->executeSimple($this->protocol->command()->unselect(), 'UNSELECT'); }

    /** @return list<MailboxInfo> */
    public function listMailboxes(string $reference = '', string $pattern = '*'): array
    {
        [$tag, $bytes] = $this->protocol->command()->list($reference, $pattern);
        $this->write($bytes);
        $this->readUntilTagged($tag);
        $result = [];
        foreach ($this->protocol->drainUnsolicitedByType('LIST') as $response) {
            $result[] = $this->protocol->interpreter()->interpretList($response);
        }
        return $result;
    }

    /** @return list<MailboxInfo> */
    public function lsub(string $reference = '', string $pattern = '*'): array
    {
        [$tag, $bytes] = $this->protocol->command()->lsub($reference, $pattern);
        $this->write($bytes);
        $this->readUntilTagged($tag);
        $result = [];
        foreach ($this->protocol->drainUnsolicitedByType('LSUB') as $response) {
            $result[] = $this->protocol->interpreter()->interpretList($response);
        }
        return $result;
    }

    /**
     * LIST with selection and return options (RFC 5258).
     *
     * @param list<string> $selectOptions e.g. ['SUBSCRIBED', 'REMOTE']
     * @param list<string> $returnOptions e.g. ['CHILDREN', 'SPECIAL-USE']
     * @return list<MailboxInfo>
     */
    public function listExtended(string $reference = '', string $pattern = '*', array $selectOptions = [], array $returnOptions = []): array
    {
        [$tag, $bytes] = $this->protocol->command()->listExtended($reference, $pattern, $selectOptions, $returnOptions);
        $this->write($bytes);
        $this->readUntilTagged($tag);
        $result = [];
        foreach ($this->protocol->drainUnsolicitedByType('LIST') as $response) {
            $result[] = $this->protocol->interpreter()->interpretList($response);
        }
        return $result;
    }

    /**
     * LIST with STATUS (RFC 5819). Returns mailboxes with inline status.
     *
     * @param list<string> $statusItems e.g. ['MESSAGES', 'UNSEEN']
     * @return list<MailboxInfo>
     */
    public function listStatus(string $reference = '', string $pattern = '*', array $statusItems = ['MESSAGES', 'UNSEEN']): array
    {
        [$tag, $bytes] = $this->protocol->command()->listStatus($reference, $pattern, $statusItems);
        $this->write($bytes);
        $this->readUntilTagged($tag);
        $result = [];
        foreach ($this->protocol->drainUnsolicitedByType('LIST') as $response) {
            $result[] = $this->protocol->interpreter()->interpretList($response);
        }
        return $result;
    }

    /** @param list<string> $items */
    public function status(string $mailbox, array $items = ['MESSAGES', 'UNSEEN', 'UIDNEXT', 'UIDVALIDITY']): StatusInfo
    {
        [$tag, $bytes] = $this->protocol->command()->status($mailbox, $items);
        $this->write($bytes);
        $this->readUntilTagged($tag);
        foreach ($this->protocol->drainUnsolicitedByType('STATUS') as $response) {
            return $this->protocol->interpreter()->interpretStatus($response);
        }
        throw new RuntimeException('No STATUS response received');
    }

    // === MESSAGES ===

    /**
     * @param list<string> $items
     * @return list<FetchResult>
     */
    public function fetch(string $sequence, array $items = ['FLAGS', 'ENVELOPE', 'RFC822.SIZE']): array
    {
        [$tag, $bytes] = $this->protocol->command()->fetch($sequence, '(' . implode(' ', $items) . ')');
        $this->write($bytes);
        $this->readUntilTagged($tag);
        return $this->protocol->drainFetchResults();
    }

    /**
     * @param list<string> $items
     * @return list<FetchResult>
     */
    public function uidFetch(string $sequence, array $items = ['FLAGS', 'ENVELOPE', 'RFC822.SIZE']): array
    {
        [$tag, $bytes] = $this->protocol->command()->uidFetch($sequence, '(' . implode(' ', $items) . ')');
        $this->write($bytes);
        $this->readUntilTagged($tag);
        return $this->protocol->drainFetchResults();
    }

    /**
     * @param list<string> $items
     * @param callable(FetchResult): void $callback
     */
    public function fetchStream(string $sequence, array $items, callable $callback): void
    {
        $this->doFetchStream($this->protocol->command()->fetch($sequence, '(' . implode(' ', $items) . ')'), $callback);
    }

    /**
     * @param list<string> $items
     * @param callable(FetchResult): void $callback
     */
    public function uidFetchStream(string $sequence, array $items, callable $callback): void
    {
        $this->doFetchStream($this->protocol->command()->uidFetch($sequence, '(' . implode(' ', $items) . ')'), $callback);
    }

    public function fetchBinary(string $sequence, string $section): ?string
    {
        [$tag, $bytes] = $this->protocol->command()->fetch($sequence, '(BINARY[' . $section . '])');
        $this->write($bytes);
        $this->readUntilTagged($tag);
        $results = $this->protocol->drainFetchResults();
        return $this->extractBinaryContent($results);
    }

    public function uidFetchBinary(string $uid, string $section): ?string
    {
        [$tag, $bytes] = $this->protocol->command()->uidFetch($uid, '(BINARY[' . $section . '])');
        $this->write($bytes);
        $this->readUntilTagged($tag);
        $results = $this->protocol->drainFetchResults();
        return $this->extractBinaryContent($results);
    }

    /** @return list<int> */
    public function search(string $criteria): array
    {
        [$tag, $bytes] = $this->protocol->command()->search($criteria);
        $this->write($bytes);
        $this->readUntilTagged($tag);
        foreach ($this->protocol->drainUnsolicitedByType('SEARCH') as $response) {
            return $this->protocol->interpreter()->interpretSearch($response);
        }
        return [];
    }

    /** @return list<int> */
    public function uidSearch(string $criteria): array
    {
        [$tag, $bytes] = $this->protocol->command()->uidSearch($criteria);
        $this->write($bytes);
        $this->readUntilTagged($tag);
        foreach ($this->protocol->drainUnsolicitedByType('SEARCH') as $response) {
            return $this->protocol->interpreter()->interpretSearch($response);
        }
        return [];
    }

    /**
     * Server-side sort (RFC 5256). Returns sorted sequence numbers.
     *
     * @param string $criteria Sort criteria e.g. "(DATE SUBJECT)" or "(REVERSE DATE)"
     * @return list<int>
     */
    public function sort(string $criteria, string $searchCriteria = 'ALL', string $charset = 'UTF-8'): array
    {
        [$tag, $bytes] = $this->protocol->command()->sort($criteria, $charset, $searchCriteria);
        $this->write($bytes);
        $this->readUntilTagged($tag);
        foreach ($this->protocol->drainUnsolicitedByType('SORT') as $response) {
            return $this->protocol->interpreter()->interpretSearch($response);
        }
        return [];
    }

    /** @return list<int> */
    public function uidSort(string $criteria, string $searchCriteria = 'ALL', string $charset = 'UTF-8'): array
    {
        [$tag, $bytes] = $this->protocol->command()->uidSort($criteria, $charset, $searchCriteria);
        $this->write($bytes);
        $this->readUntilTagged($tag);
        foreach ($this->protocol->drainUnsolicitedByType('SORT') as $response) {
            return $this->protocol->interpreter()->interpretSearch($response);
        }
        return [];
    }

    /**
     * Server-side threading (RFC 5256). Returns thread structure.
     *
     * @param string $algorithm REFERENCES or ORDEREDSUBJECT
     * @return list<array> Thread structure (nested arrays of sequence numbers)
     */
    public function thread(string $algorithm = 'REFERENCES', string $searchCriteria = 'ALL', string $charset = 'UTF-8'): array
    {
        [$tag, $bytes] = $this->protocol->command()->thread($algorithm, $charset, $searchCriteria);
        $this->write($bytes);
        $this->readUntilTagged($tag);
        $threads = [];
        foreach ($this->protocol->drainUnsolicitedByType('THREAD') as $response) {
            foreach ($response->tokens as $token) {
                $threads[] = $this->parseThreadToken($token);
            }
        }
        return $threads;
    }

    /** @return list<array> */
    public function uidThread(string $algorithm = 'REFERENCES', string $searchCriteria = 'ALL', string $charset = 'UTF-8'): array
    {
        [$tag, $bytes] = $this->protocol->command()->uidThread($algorithm, $charset, $searchCriteria);
        $this->write($bytes);
        $this->readUntilTagged($tag);
        $threads = [];
        foreach ($this->protocol->drainUnsolicitedByType('THREAD') as $response) {
            foreach ($response->tokens as $token) {
                $threads[] = $this->parseThreadToken($token);
            }
        }
        return $threads;
    }

    /** @param list<string> $flags */
    public function store(string $sequence, string $action, array $flags): void
    {
        $this->executeSimple($this->protocol->command()->store($sequence, $action, '(' . implode(' ', $flags) . ')'), 'STORE');
    }

    /** @param list<string> $flags */
    public function uidStore(string $sequence, string $action, array $flags): void
    {
        $this->executeSimple($this->protocol->command()->uidStore($sequence, $action, '(' . implode(' ', $flags) . ')'), 'UID STORE');
    }

    public function copy(string $sequence, string $mailbox): void { $this->executeSimple($this->protocol->command()->copy($sequence, $mailbox), 'COPY'); }
    public function uidCopy(string $sequence, string $mailbox): void { $this->executeSimple($this->protocol->command()->uidCopy($sequence, $mailbox), 'UID COPY'); }
    public function move(string $sequence, string $mailbox): void { $this->executeSimple($this->protocol->command()->move($sequence, $mailbox), 'MOVE'); }
    public function uidMove(string $sequence, string $mailbox): void { $this->executeSimple($this->protocol->command()->uidMove($sequence, $mailbox), 'UID MOVE'); }

    /** @return list<int> */
    public function expunge(): array
    {
        [$tag, $bytes] = $this->protocol->command()->expunge();
        $this->write($bytes);
        $this->readUntilTagged($tag);
        return $this->protocol->drainExpunges();
    }

    /** @return list<int> */
    public function uidExpunge(string $uidSet): array
    {
        [$tag, $bytes] = $this->protocol->command()->uidExpunge($uidSet);
        $this->write($bytes);
        $this->readUntilTagged($tag);
        return $this->protocol->drainExpunges();
    }

    /**
     * @param list<string> $flags
     * @return int|null UID if server supports APPENDUID
     */
    public function append(string $mailbox, string $message, array $flags = [], ?string $date = null): ?int
    {
        $flagObjs = array_map(static fn(string $f): Flag => new Flag($f), $flags);
        [$tag, $bytes] = $this->protocol->command()->append($mailbox, $message, $flagObjs, $date);

        $literalPos = strpos($bytes, "}\r\n");
        if ($literalPos !== false) {
            $this->write(substr($bytes, 0, $literalPos + 3));
            $this->readUntilContinuation();
            $this->write(substr($bytes, $literalPos + 3));
        } else {
            $this->write($bytes);
        }

        $tagged = $this->readUntilTagged($tag);
        if (!$tagged->isOk()) {
            throw new RuntimeException('APPEND failed: ' . $tagged->responseText->text);
        }
        if ($tagged->responseText->code === ResponseCode::AppendUid && count($tagged->responseText->codeData) >= 2) {
            return (int) $tagged->responseText->codeData[1];
        }
        return null;
    }

    // === IDLE ===

    /**
     * Enter IDLE mode. Blocks until callback returns false or connection drops.
     * Auto re-issues IDLE every $timeout seconds.
     *
     * @param callable(IdleNotification): bool $callback Return false to stop
     * @param int $timeout Seconds before re-issuing IDLE (default 29 min)
     */
    public function idle(callable $callback, int $timeout = 1740): void
    {
        while (true) {
            [$tag, $bytes] = $this->protocol->command()->idle();
            $this->write($bytes);
            $this->readUntilContinuation();

            $idleStart = time();
            while (time() - $idleStart < $timeout) {
                $data = $this->readWithTimeout(1);
                if ($data === null) {
                    continue;
                }
                if ($data === '') {
                    throw new ConnectionException('Connection lost during IDLE');
                }
                $this->protocol->onData($data);
                foreach ($this->protocol->drainAllUnsolicited() as $response) {
                    $notification = $this->protocol->buildIdleNotification($response);
                    if ($callback($notification) === false) {
                        $this->write($this->protocol->command()->done());
                        $this->readUntilTagged($tag);
                        return;
                    }
                }
            }

            $this->write($this->protocol->command()->done());
            $this->readUntilTagged($tag);
        }
    }

    // === ACL (RFC 4314) ===

    public function getAcl(string $mailbox): string
    {
        [$tag, $bytes] = $this->protocol->command()->getAcl($mailbox);
        $this->write($bytes);
        $this->readUntilTagged($tag);
        foreach ($this->protocol->drainUnsolicitedByType('ACL') as $response) {
            $parts = [];
            foreach ($response->tokens as $token) {
                $parts[] = $token->stringValue();
            }
            return implode(' ', $parts);
        }
        return '';
    }

    public function setAcl(string $mailbox, string $identifier, string $rights): void
    {
        $this->executeSimple($this->protocol->command()->setAcl($mailbox, $identifier, $rights), 'SETACL');
    }

    public function deleteAcl(string $mailbox, string $identifier): void
    {
        $this->executeSimple($this->protocol->command()->deleteAcl($mailbox, $identifier), 'DELETEACL');
    }

    public function myRights(string $mailbox): string
    {
        [$tag, $bytes] = $this->protocol->command()->myRights($mailbox);
        $this->write($bytes);
        $this->readUntilTagged($tag);
        foreach ($this->protocol->drainUnsolicitedByType('MYRIGHTS') as $response) {
            $parts = [];
            foreach ($response->tokens as $token) {
                $parts[] = $token->stringValue();
            }
            return implode(' ', $parts);
        }
        return '';
    }

    public function listRights(string $mailbox, string $identifier): string
    {
        [$tag, $bytes] = $this->protocol->command()->listRights($mailbox, $identifier);
        $this->write($bytes);
        $this->readUntilTagged($tag);
        foreach ($this->protocol->drainUnsolicitedByType('LISTRIGHTS') as $response) {
            $parts = [];
            foreach ($response->tokens as $token) {
                $parts[] = $token->stringValue();
            }
            return implode(' ', $parts);
        }
        return '';
    }

    // === METADATA (RFC 5464) ===

    /**
     * @param list<string> $entries
     * @return array<string, string> entry => value map
     */
    public function getMetadata(string $mailbox, array $entries): array
    {
        [$tag, $bytes] = $this->protocol->command()->getMetadata($mailbox, $entries);
        $this->write($bytes);
        $this->readUntilTagged($tag);
        $result = [];
        foreach ($this->protocol->drainUnsolicitedByType('METADATA') as $response) {
            $tokens = $response->tokens;
            // Skip mailbox name token, then parse key-value pairs
            for ($i = 1, $len = count($tokens); $i + 1 < $len; $i += 2) {
                $result[$tokens[$i]->stringValue()] = $tokens[$i + 1]->stringValue();
            }
        }
        return $result;
    }

    /** @param array<string, string|null> $entryValues entry => value (null to delete) */
    public function setMetadata(string $mailbox, array $entryValues): void
    {
        $this->executeSimple($this->protocol->command()->setMetadata($mailbox, $entryValues), 'SETMETADATA');
    }

    // === QUOTA (RFC 2087) ===

    /** @return array<string, mixed> */
    public function getQuota(string $root): array
    {
        [$tag, $bytes] = $this->protocol->command()->getQuota($root);
        $this->write($bytes);
        $this->readUntilTagged($tag);
        $result = [];
        foreach ($this->protocol->drainUnsolicitedByType('QUOTA') as $response) {
            $tokens = $response->tokens;
            if (isset($tokens[0])) {
                $result['root'] = $tokens[0]->stringValue();
            }
            if (isset($tokens[1]) && $tokens[1]->isList()) {
                $items = $tokens[1]->children;
                for ($i = 0, $len = count($items); $i + 2 < $len; $i += 3) {
                    $resource = strtoupper($items[$i]->stringValue());
                    $result[$resource] = [
                        'usage' => $items[$i + 1]->intValue(),
                        'limit' => $items[$i + 2]->intValue(),
                    ];
                }
            }
        }
        return $result;
    }

    /** @return array{mailbox?: string, roots?: list<string>, quotas?: array<string, array<string, array{usage: int, limit: int}>>} */
    public function getQuotaRoot(string $mailbox): array
    {
        [$tag, $bytes] = $this->protocol->command()->getQuotaRoot($mailbox);
        $this->write($bytes);
        $this->readUntilTagged($tag);
        /** @var array{mailbox?: string, roots?: list<string>, quotas?: array<string, array<string, array{usage: int, limit: int}>>} $result */
        $result = [];
        foreach ($this->protocol->drainUnsolicitedByType('QUOTAROOT') as $response) {
            $tokens = $response->tokens;
            if (isset($tokens[0])) {
                $result['mailbox'] = $tokens[0]->stringValue();
            }
            $roots = [];
            for ($i = 1, $len = count($tokens); $i < $len; $i++) {
                $roots[] = $tokens[$i]->stringValue();
            }
            $result['roots'] = $roots;
        }
        // Also drain the QUOTA responses that follow QUOTAROOT
        foreach ($this->protocol->drainUnsolicitedByType('QUOTA') as $response) {
            $tokens = $response->tokens;
            if (isset($tokens[0], $tokens[1]) && $tokens[1]->isList()) {
                $root = $tokens[0]->stringValue();
                $items = $tokens[1]->children;
                for ($i = 0, $len = count($items); $i + 2 < $len; $i += 3) {
                    $resource = strtoupper($items[$i]->stringValue());
                    $result['quotas'][$root][$resource] = [
                        'usage' => $items[$i + 1]->intValue(),
                        'limit' => $items[$i + 2]->intValue(),
                    ];
                }
            }
        }
        return $result;
    }

    public function setQuota(string $root, int $storageLimit): void
    {
        $this->executeSimple($this->protocol->command()->setQuota($root, $storageLimit), 'SETQUOTA');
    }

    // === OTHER ===

    public function namespace(): NamespaceSet
    {
        [$tag, $bytes] = $this->protocol->command()->namespace();
        $this->write($bytes);
        $this->readUntilTagged($tag);
        foreach ($this->protocol->drainUnsolicitedByType('NAMESPACE') as $response) {
            return $this->protocol->interpreter()->interpretNamespace($response);
        }
        throw new RuntimeException('No NAMESPACE response received');
    }

    /**
     * @param list<string> $capabilities
     * @return list<string>
     */
    public function enable(array $capabilities): array
    {
        [$tag, $bytes] = $this->protocol->command()->enable($capabilities);
        $this->write($bytes);
        $this->readUntilTagged($tag);
        $enabled = [];
        foreach ($this->protocol->drainUnsolicitedByType('ENABLED') as $response) {
            foreach ($response->tokens as $token) {
                $enabled[] = strtoupper($token->stringValue());
            }
        }
        return $enabled;
    }

    /** @param array<string, string>|null $params */
    public function id(?array $params = null): void
    {
        $this->executeSimple($this->protocol->command()->id($params), 'ID');
    }

    public function noop(): void { $this->executeSimple($this->protocol->command()->noop(), 'NOOP'); }

    public function logout(): void
    {
        [$tag, $bytes] = $this->protocol->command()->logout();
        $this->write($bytes);
        try {
            $this->readUntilTagged($tag);
        } catch (ConnectionException) {
        }
        $this->disconnect();
    }

    public function session(): Session { return $this->protocol->session(); }
    public function recentExists(): ?int { return $this->protocol->lastExists(); }
    /** @return list<int> */
    public function recentExpunges(): array { return $this->protocol->drainExpunges(); }

    // === INTERNAL: I/O ===

    private function read(): string
    {
        $stream = $this->stream();
        $data = @fread($stream, 8192);
        if ($data === false) {
            throw new ConnectionException('Read failed');
        }
        if ($data === '' && feof($stream)) {
            return '';
        }
        $meta = stream_get_meta_data($stream);
        if ($meta['timed_out']) {
            throw new ConnectionException('Read timed out');
        }
        return $data;
    }

    private function readWithTimeout(int $seconds): ?string
    {
        $stream = $this->stream();
        stream_set_timeout($stream, $seconds);
        $data = @fread($stream, 8192);
        $meta = stream_get_meta_data($stream);
        stream_set_timeout($stream, $this->readTimeout);
        if ($meta['timed_out']) {
            return null;
        }
        if ($data === false) {
            throw new ConnectionException('Read failed');
        }
        if ($data === '' && feof($stream)) {
            return '';
        }
        return $data;
    }

    private function write(string $data): void
    {
        $stream = $this->stream();
        $length = strlen($data);
        $written = 0;
        while ($written < $length) {
            $bytes = @fwrite($stream, substr($data, $written));
            if ($bytes === false || $bytes === 0) {
                throw new ConnectionException('Write failed');
            }
            $written += $bytes;
        }
    }

    private function upgradeToTls(): void
    {
        $result = @stream_socket_enable_crypto(
            $this->stream(),
            true,
            STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT,
        );
        if ($result !== true) {
            throw new ConnectionException('TLS upgrade failed');
        }
    }

    /** @return resource */
    private function stream()
    {
        if ($this->stream === null) {
            throw new ConnectionException('Not connected');
        }
        return $this->stream;
    }

    // === INTERNAL: PROTOCOL ===

    private function readUntilTagged(Tag $expectedTag): TaggedResponse
    {
        while (true) {
            $bytes = $this->read();
            if ($bytes === '') {
                throw new ConnectionException('Connection closed by server');
            }
            $this->protocol->onData($bytes);
            $tagged = $this->protocol->consumeTaggedResponse($expectedTag);
            if ($tagged !== null) {
                return $tagged;
            }
        }
    }

    private function readUntilTaggedOrContinuation(Tag $expectedTag): ?TaggedResponse
    {
        while (true) {
            $bytes = $this->read();
            if ($bytes === '') {
                throw new ConnectionException('Connection closed by server');
            }
            $this->protocol->onData($bytes);
            $tagged = $this->protocol->consumeTaggedResponse($expectedTag);
            if ($tagged !== null) {
                return $tagged;
            }
            if ($this->protocol->consumeContinuation() !== null) {
                return null;
            }
        }
    }

    private function readUntilContinuation(): ContinuationResponse
    {
        while (true) {
            $bytes = $this->read();
            if ($bytes === '') {
                throw new ConnectionException('Connection closed while waiting for continuation');
            }
            $this->protocol->onData($bytes);
            $cont = $this->protocol->consumeContinuation();
            if ($cont !== null) {
                return $cont;
            }
        }
    }

    private function doSelect(string $mailbox, bool $readOnly): SelectResult
    {
        $this->protocol->resetSelectState();
        [$tag, $bytes] = $readOnly
            ? $this->protocol->command()->examine($mailbox)
            : $this->protocol->command()->select($mailbox);
        $this->write($bytes);
        $tagged = $this->readUntilTagged($tag);
        if (!$tagged->isOk()) {
            throw new RuntimeException(($readOnly ? 'EXAMINE' : 'SELECT') . ' failed: ' . $tagged->responseText->text);
        }
        return $this->buildSelectResult($tagged);
    }

    private function buildSelectResult(TaggedResponse $tagged): SelectResult
    {
        return new SelectResult(
            exists: $this->protocol->lastExists() ?? 0,
            recent: $this->protocol->lastRecent(),
            flags: $this->protocol->lastFlags(),
            permanentFlags: $this->protocol->lastPermanentFlags(),
            uidValidity: $this->protocol->lastUidValidity() ?? 0,
            uidNext: $this->protocol->lastUidNext() ?? 0,
            highestModseq: $this->protocol->lastHighestModseq(),
            readWrite: $tagged->responseText->code === ResponseCode::ReadWrite,
        );
    }

    /**
     * @param array{0: Tag, 1: string} $command
     * @param callable(FetchResult): void $callback
     */
    private function doFetchStream(array $command, callable $callback): void
    {
        [$tag, $bytes] = $command;
        $this->write($bytes);
        while (true) {
            $data = $this->read();
            if ($data === '') {
                throw new ConnectionException('Connection closed during FETCH');
            }
            $this->protocol->onData($data);
            foreach ($this->protocol->drainFetchResults() as $result) {
                $callback($result);
            }
            $tagged = $this->protocol->consumeTaggedResponse($tag);
            if ($tagged !== null) {
                foreach ($this->protocol->drainFetchResults() as $result) {
                    $callback($result);
                }
                return;
            }
        }
    }

    /** @param array{0: Tag, 1: string} $command */
    private function executeSimple(array $command, string $name): void
    {
        [$tag, $bytes] = $command;
        $this->write($bytes);
        $tagged = $this->readUntilTagged($tag);
        if (!$tagged->isOk()) {
            throw new RuntimeException($name . ' failed: ' . $tagged->responseText->text);
        }
    }

    /** @param list<FetchResult> $results */
    private function extractBinaryContent(array $results): ?string
    {
        if ($results === []) {
            return null;
        }
        foreach ($results[0]->bodySections as $content) {
            if ($content !== null) {
                return $content;
            }
        }
        return null;
    }

    /**
     * Recursively parse a THREAD token into a nested array structure.
     *
     * @return list<int|list<mixed>>
     */
    private function parseThreadToken(Token $token): array
    {
        if ($token->isList()) {
            $result = [];
            foreach ($token->children as $child) {
                $parsed = $this->parseThreadToken($child);
                if ($parsed !== []) {
                    $result[] = $parsed;
                }
            }
            return $result;
        }
        return [$token->intValue()];
    }
}
