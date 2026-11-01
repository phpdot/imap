<?php

declare(strict_types=1);

/**
 * Per-connection server handler. Wraps the protocol engine with the developer's ImapHandler.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Mail\IMAP\Connection;

use PHPdot\Mail\IMAP\DataType\DTO\FetchResult;
use PHPdot\Mail\IMAP\DataType\DTO\MailboxInfo;
use PHPdot\Mail\IMAP\DataType\DTO\StatusInfo;
use PHPdot\Mail\IMAP\DataType\ValueObject\CapabilitySet;
use PHPdot\Mail\IMAP\DataType\ValueObject\Flag;
use PHPdot\Mail\IMAP\DataType\ValueObject\NamespaceSet;
use PHPdot\Mail\IMAP\ImapHandler;
use PHPdot\Mail\IMAP\Protocol\WireFormat;
use PHPdot\Mail\IMAP\Result\SelectResult;
use PHPdot\Mail\IMAP\Server\Event\AclEvent;
use PHPdot\Mail\IMAP\Server\Event\AppendEvent;
use PHPdot\Mail\IMAP\Server\Event\AuthenticateEvent;
use PHPdot\Mail\IMAP\Server\Event\CompressEvent;
use PHPdot\Mail\IMAP\Server\Event\CopyEvent;
use PHPdot\Mail\IMAP\Server\Event\Event;
use PHPdot\Mail\IMAP\Server\Event\FetchEvent;
use PHPdot\Mail\IMAP\Server\Event\IdEvent;
use PHPdot\Mail\IMAP\Server\Event\IdleEvent;
use PHPdot\Mail\IMAP\Server\Event\ListEvent;
use PHPdot\Mail\IMAP\Server\Event\LoginEvent;
use PHPdot\Mail\IMAP\Server\Event\MailboxEvent;
use PHPdot\Mail\IMAP\Server\Event\MetadataEvent;
use PHPdot\Mail\IMAP\Server\Event\MoveEvent;
use PHPdot\Mail\IMAP\Server\Event\QuotaEvent;
use PHPdot\Mail\IMAP\Server\Event\RenameEvent;
use PHPdot\Mail\IMAP\Server\Event\SearchEvent;
use PHPdot\Mail\IMAP\Server\Event\SelectEvent;
use PHPdot\Mail\IMAP\Server\Event\SimpleEvent;
use PHPdot\Mail\IMAP\Server\Event\StatusEvent;
use PHPdot\Mail\IMAP\Server\Event\StoreEvent;
use PHPdot\Mail\IMAP\Server\Event\UidExpungeEvent;
use PHPdot\Mail\IMAP\Server\Response\ResponseBuilder;
use PHPdot\Mail\IMAP\Server\ServerProtocol;

final class ServerConnection
{
    private readonly ServerProtocol $protocol;
    private readonly ConnectionContext $context;
    private readonly ResponseBuilder $rb;

    /** @var list<string|\Iterator<mixed, string>> Untagged data of the command in progress. */
    private array $pendingData = [];

    /** @var list<MailboxChange> Changes from other sessions not yet reported to this client. */
    private array $queuedChanges = [];

    /** Transport write callback — where notifier pushes land on the socket. */
    private \Closure|null $pushWriter = null;

    public function __construct(
        private readonly ImapHandler $handler,
        private readonly NotifierInterface|null $notifier = null,
        string|null $remoteAddress = null,
        int $maxLiteralBytes = 52428800,
    ) {
        $this->context = new ConnectionContext($remoteAddress ?? '127.0.0.1');

        $this->protocol = new ServerProtocol(null, $this->context->session(), $maxLiteralBytes);
        $this->rb = new ResponseBuilder();
        $this->registerHandlers();

        $this->protocol->setResponseInterceptor(function (array $responses, string|null $commandName): array {
            $data = $this->drainPendingData();
            if ($this->mayReportChanges($commandName)) {
                foreach ($this->flushQueuedChanges() as $line) {
                    $data[] = $line;
                }
            }

            return $data === [] ? $responses : array_merge($data, $responses);
        });
    }

    public function greeting(string $text = 'IMAP4rev2 Server ready', CapabilitySet|null $capabilities = null): string
    {
        if ($capabilities !== null) {
            $this->context->session()->setCapabilities($capabilities);
        }

        return $this->protocol->greeting($capabilities, $text);
    }

    /**
     * Feed client bytes; returns the complete response bytes.
     *
     * Lazily produced results are materialized here. A transport that can
     * apply backpressure should use onDataDeferred() instead.
     *
     * @return list<string>
     */
    public function onData(string $bytes): array
    {
        $this->pendingData = [];

        return $this->protocol->onData($bytes);
    }

    /**
     * Feed client bytes; returns the responses in order, where an entry is
     * either wire bytes or an iterator yielding wire bytes on demand.
     *
     * A host that accepts a FETCH with a generator of FetchResult gets it
     * back here unconsumed: the transport pulls one message at a time as the
     * socket drains, so a whole-mailbox fetch never sits in memory at once.
     * Until an iterator is exhausted, do not feed further client bytes.
     *
     * @return list<string|\Iterator<mixed, string>>
     */
    public function onDataDeferred(string $bytes): array
    {
        $this->pendingData = [];

        return $this->protocol->onDataDeferred($bytes);
    }

    /**
     * Whether pipelined commands are held back behind a lazy response.
     */
    public function hasBacklog(): bool
    {
        return $this->protocol->hasBacklog();
    }

    /**
     * Run the held-back commands once the lazy response before them has
     * been fully consumed.
     *
     * @return list<string|\Iterator<mixed, string>>
     */
    public function resume(): array
    {
        $this->pendingData = [];

        return $this->protocol->resume();
    }

    /**
     * Total client lines processed — the transport's basis for rate limiting.
     */
    public function linesProcessed(): int
    {
        return $this->protocol->linesProcessed();
    }

    /**
     * Whether queued mailbox changes may be reported with this command.
     *
     * RFC 3501 §7.4.1: EXPUNGE must not be sent while responding to FETCH,
     * STORE or SEARCH — the client's sequence numbers in those commands
     * would be renumbered under it. Their UID forms are unaffected.
     */
    private function mayReportChanges(string|null $commandName): bool
    {
        if ($this->context->selectedMailbox() === null) {
            return false;
        }

        return !in_array($commandName, ['FETCH', 'STORE', 'SEARCH'], true);
    }

    /**
     * A change published by another session (or a delivery) for the
     * selected mailbox. Sent at once to an IDLE-ing client; otherwise held
     * until the next command that may carry it.
     */
    private function onMailboxChange(MailboxChange $change): void
    {
        $this->queuedChanges[] = $change;

        if ($this->protocol->isIdling() && $this->pushWriter !== null) {
            $lines = $this->flushQueuedChanges();
            if ($lines !== []) {
                ($this->pushWriter)(implode('', $lines));
            }
        }
    }

    /**
     * Apply queued changes to this session's sequence map and render them
     * as untagged responses in this session's own numbering.
     *
     * The map is only updated here — at the moment the client is told — so
     * the sequence numbers the client uses always match the server's.
     *
     * @return list<string>
     */
    private function flushQueuedChanges(): array
    {
        $changes = $this->queuedChanges;
        $this->queuedChanges = [];
        $tracking = $this->context->tracksUids();
        $lines = [];

        foreach ($changes as $change) {
            if ($change->type === 'exists') {
                if ($tracking && $change->uid !== null) {
                    $known = count($this->context->uidList());
                    $count = $this->context->appendUid($change->uid);
                    if ($count !== $known) {
                        $lines[] = $this->rb->exists($count);
                    }
                } elseif (!$tracking && $change->count !== null) {
                    $lines[] = $this->rb->exists($change->count);
                }
                continue;
            }

            if (!$tracking || $change->uid === null) {
                continue;
            }

            if ($change->type === 'expunge') {
                $seq = $this->context->removeUid($change->uid);
                if ($seq !== null) {
                    $lines[] = $this->rb->expunge($seq);
                }
                continue;
            }

            if ($change->type === 'flags' && $change->flags !== null) {
                $seq = $this->context->uidToSequence($change->uid);
                if ($seq !== null) {
                    $line = $this->pushFlagUpdate($seq, $change->uid, $change->flags);

                    if ($change->modseq !== null && $this->context->session()->isCondstoreEnabled()) {
                        $line = substr($line, 0, -3) . ' MODSEQ (' . $change->modseq . "))\r\n";
                    }
                    $lines[] = $line;
                }
            }
        }

        return $lines;
    }

    /**
     * Render a SEARCH result: classic `* SEARCH`, or `* ESEARCH` when the
     * command carried RETURN options (RFC 4731). The host returns the
     * matching numbers — or ['results' => list<int>, 'modseqs' => list<int>]
     * (parallel lists) when the criteria used MODSEQ (RFC 7162 §3.1.5).
     */
    private function searchResponse(SearchEvent $event, mixed $results): string
    {
        $numbers = [];
        $modseqs = [];

        if (is_array($results)) {
            $list = isset($results['results']) && is_array($results['results']) ? $results['results'] : $results;
            foreach ($list as $number) {
                if (is_int($number)) {
                    $numbers[] = $number;
                }
            }
            if (isset($results['modseqs']) && is_array($results['modseqs'])) {
                foreach ($results['modseqs'] as $modseq) {
                    if (is_int($modseq)) {
                        $modseqs[] = $modseq;
                    }
                }
            }
        }

        $hasModseq = $numbers !== [] && count($modseqs) === count($numbers);
        $options = $event->returnOptions();

        if ($options === []) {
            return $hasModseq
                ? $this->rb->searchWithModseq($numbers, max($modseqs))
                : $this->rb->search($numbers);
        }

        $data = [];
        $relevant = [];

        if ($numbers !== []) {
            $byNumber = $hasModseq ? array_combine($numbers, $modseqs) : [];
            $min = min($numbers);
            $max = max($numbers);

            if (in_array('MIN', $options, true)) {
                array_push($data, 'MIN', (string) $min);
                $relevant[] = $byNumber[$min] ?? 0;
            }
            if (in_array('MAX', $options, true)) {
                array_push($data, 'MAX', (string) $max);
                $relevant[] = $byNumber[$max] ?? 0;
            }
            if (in_array('ALL', $options, true)) {
                array_push($data, 'ALL', \PHPdot\Mail\IMAP\DataType\ValueObject\SequenceSet::fromArray($numbers)->toWireString());
            }
        }

        if (in_array('COUNT', $options, true)) {
            array_push($data, 'COUNT', (string) count($numbers));
        }

        if ($hasModseq) {

            $wide = in_array('ALL', $options, true) || in_array('COUNT', $options, true);
            array_push($data, 'MODSEQ', (string) max($wide || $relevant === [] ? $modseqs : $relevant));
        }

        return $this->rb->esearch($event->command->tag->value, $event->isUid(), $data);
    }

    private function subscribeToSelected(string $mailbox): void
    {
        if ($this->notifier === null) {
            return;
        }

        $this->notifier->subscribe(
            $this->context->channelFor($mailbox),
            $this->context->connectionId(),
            function (MailboxChange $change): void {
                $this->onMailboxChange($change);
            },
        );
    }

    /**
     * Leave the selected mailbox: drop the subscription, the sequence map
     * and any changes still queued for it.
     */
    private function deselect(): void
    {
        if ($this->notifier !== null && $this->context->selectedMailbox() !== null) {
            $this->notifier->unsubscribe(
                $this->context->channelFor($this->context->selectedMailbox()),
                $this->context->connectionId(),
            );
        }
        $this->queuedChanges = [];
        $this->context->trackUids(null);
        $this->context->setSelectedMailbox(null);
    }

    /**
     * Emit untagged EXPUNGE for sequence numbers the host reports in
     * PRE-expunge numbering. Sent highest first, so every number is still
     * valid when the client applies it, and mirrored into the sequence map.
     */
    private function reportExpunged(mixed $seqs): void
    {
        if (!is_array($seqs)) {
            return;
        }

        $seqs = array_values(array_unique(array_filter($seqs, 'is_int')));
        rsort($seqs);

        $uids = $this->context->uidList();
        foreach ($seqs as $seq) {
            $this->pendingData[] = $this->rb->expunge($seq);
            if ($this->context->tracksUids() && isset($uids[$seq - 1])) {
                $this->context->removeUid($uids[$seq - 1]);
            }
        }
    }

    /** @return list<string|\Iterator<mixed, string>> */
    private function drainPendingData(): array
    {
        $data = $this->pendingData;
        $this->pendingData = [];
        return $data;
    }

    public function onClose(): void
    {
        $this->deselect();
    }

    /**
     * Whether the client has logged out — the transport closes the
     * connection once the BYE and tagged OK are flushed.
     */
    public function isLoggedOut(): bool
    {
        return $this->protocol->isLoggedOut();
    }

    public function pushExists(int $count): string
    {
        return $this->protocol->pushExists($count);
    }

    /**
     * Set the transport's write callback — invoked when the notifier pushes
     * a mailbox change for this connection (e.g. untagged EXISTS to an
     * IDLE-ing client). Only the transport owns the socket.
     */
    public function setPushWriter(\Closure $writer): void
    {
        $this->pushWriter = $writer;
    }

    public function pushExpunge(int $sequenceNumber): string
    {
        return $this->protocol->pushExpunge($sequenceNumber);
    }

    /** @param list<string> $flags */
    public function pushFlagUpdate(int $sequenceNumber, int $uid, array $flags): string
    {
        $flagObjs = array_map(static fn(string $f): Flag => new Flag($f), $flags);
        return $this->protocol->pushFlagUpdate($sequenceNumber, $uid, $flagObjs);
    }

    public function context(): ConnectionContext
    {
        return $this->context;
    }

    public function isIdling(): bool
    {
        return $this->protocol->isIdling();
    }

    public function isAuthenticated(): bool
    {
        return $this->context->user() !== null;
    }

    public function selectedMailbox(): string|null
    {
        return $this->context->selectedMailbox();
    }

    /**
     * Run the host's AUTHENTICATE handler and record who authenticated: the
     * identity the host set on the context, else the PLAIN username.
     */
    private function runAuthenticate(AuthenticateEvent $event, callable $handler): void
    {
        try {
            $handler($event, $this->context);
        } catch (\Throwable $e) {
            if (!$event->isHandled()) {
                $event->reject('Authentication failed');
            }
            return;
        }

        if ($event->isAccepted() && $this->context->user() === null) {
            $credentials = $event->plainCredentials();
            $this->context->setUser($credentials['username'] ?? 'authenticated');
        }
    }

    private function registerHandlers(): void
    {
        $this->protocol->on(LoginEvent::class, function (LoginEvent $event): void {
            $this->dispatch('LOGIN', $event, function () use ($event): void {
                $this->context->setUser($event->username());
            });
        });

        $this->protocol->on(AuthenticateEvent::class, function (AuthenticateEvent $event): void {
            $handler = $this->handler->getHandler('AUTHENTICATE');
            if ($handler === null) {
                $event->reject('AUTHENTICATE not implemented');
                return;
            }

            $token = $event->initialResponse();

            if ($token === null) {
                $event->challenge('');
                $event->onChallengeResponse(function (string $response) use ($event, $handler): void {
                    $this->runAuthenticate($event, $handler);
                });
                return;
            }

            $this->runAuthenticate($event, $handler);
        });

        $this->protocol->on(SelectEvent::class, function (SelectEvent $event): void {
            $handler = $this->handler->getHandler('SELECT');
            if ($handler === null) {
                $event->reject('SELECT not implemented');
                return;
            }

            $this->deselect();

            try {
                $handler($event, $this->context);

                if (!$event->isAccepted()) {
                    return;
                }

                $result = $event->getResult();
                if ($result instanceof SelectResult) {
                    $this->context->setSelectedMailbox($event->mailbox()->name);
                    $this->context->trackUids($result->uids);

                    if ($result->flags !== []) {
                        $this->pendingData[] = $this->rb->flags($result->flags);
                    }
                    $this->pendingData[] = $this->rb->exists($result->exists);

                    if ($this->context->session()->supportsRecent()) {
                        $this->pendingData[] = $this->rb->recent($result->recent);
                    }
                    if ($result->permanentFlags !== []) {
                        $this->pendingData[] = $this->rb->okPermanentFlags($result->permanentFlags);
                    } elseif (!$result->readWrite) {

                        $this->pendingData[] = $this->rb->okPermanentFlags([], false);
                    }
                    if ($result->uidValidity > 0) {
                        $this->pendingData[] = $this->rb->okUidValidity($result->uidValidity);
                    }
                    if ($result->uidNext > 0) {
                        $this->pendingData[] = $this->rb->okUidNext($result->uidNext);
                    }
                    if ($result->highestModseq !== null) {
                        $this->pendingData[] = $this->rb->okHighestModseq($result->highestModseq);
                    }

                    $this->subscribeToSelected($event->mailbox()->name);
                }
            } catch (\Throwable $e) {
                if (!$event->isHandled()) {
                    $event->reject($e->getMessage());
                }
            }
        });

        $this->protocol->on(FetchEvent::class, function (FetchEvent $event): void {
            $this->dispatchWithResults('FETCH', $event, function (mixed $results): void {
                if (is_array($results)) {
                    foreach ($results as $fetchResult) {
                        if ($fetchResult instanceof FetchResult) {
                            $this->pendingData[] = WireFormat::fetchResponse($fetchResult);
                        }
                    }
                } elseif ($results instanceof \Traversable) {

                    $this->pendingData[] = (static function () use ($results): \Generator {
                        foreach ($results as $fetchResult) {
                            if ($fetchResult instanceof FetchResult) {
                                yield WireFormat::fetchResponse($fetchResult);
                            }
                        }
                    })();
                }
            });
        });

        $this->protocol->on(SearchEvent::class, function (SearchEvent $event): void {

            $unknown = array_diff($event->returnOptions(), ['MIN', 'MAX', 'ALL', 'COUNT']);
            if ($unknown !== []) {
                $event->reject('Unsupported SEARCH RETURN option ' . implode(' ', $unknown), \PHPdot\Mail\IMAP\DataType\Enum\ResponseStatus::Bad);
                return;
            }

            $this->dispatchWithResults('SEARCH', $event, function (mixed $results) use ($event): void {
                $this->pendingData[] = $this->searchResponse($event, $results);
            });
        });

        $this->protocol->on(StoreEvent::class, function (StoreEvent $event): void {
            $this->dispatchWithResults('STORE', $event, function (mixed $result) use ($event): void {
                if (!is_array($result)) {
                    return;
                }

                $entries = isset($result['changed']) && is_array($result['changed']) ? $result['changed'] : $result;
                $condstore = $this->context->session()->isCondstoreEnabled();

                foreach ($entries as $entry) {
                    if (!is_array($entry) || !isset($entry['seq'], $entry['flags']) || !is_int($entry['seq']) || !is_array($entry['flags'])) {
                        continue;
                    }

                    $modseq = $condstore && isset($entry['modseq']) && is_int($entry['modseq']) ? $entry['modseq'] : null;
                    $items = [];

                    if (!$event->isSilent()) {
                        if (isset($entry['uid']) && is_int($entry['uid'])) {
                            $items[] = 'UID ' . $entry['uid'];
                        }
                        $flags = [];
                        foreach ($entry['flags'] as $flag) {
                            if (is_string($flag) || $flag instanceof Flag) {
                                $flags[] = (string) $flag;
                            }
                        }
                        $items[] = 'FLAGS (' . implode(' ', $flags) . ')';
                    }

                    if ($modseq !== null) {
                        $items[] = 'MODSEQ (' . $modseq . ')';
                    }

                    if ($items !== []) {
                        $this->pendingData[] = '* ' . $entry['seq'] . ' FETCH (' . implode(' ', $items) . ")\r\n";
                    }
                }
            });
        });

        $this->protocol->on(CopyEvent::class, fn(CopyEvent $e) => $this->dispatch('COPY', $e));
        $this->protocol->on(MoveEvent::class, function (MoveEvent $event): void {
            $this->dispatchWithResults('MOVE', $event, function (mixed $result): void {

                if (is_array($result) && isset($result['expunged'])) {
                    $this->reportExpunged($result['expunged']);
                }
            });
        });
        $this->protocol->on(AppendEvent::class, fn(AppendEvent $e) => $this->dispatch('APPEND', $e));

        $this->protocol->on(IdleEvent::class, function (IdleEvent $event): void {
            $handler = $this->handler->getHandler('IDLE');
            if ($handler !== null) {
                $handler($event, $this->context);
            }
            if (!$event->isHandled()) {
                $event->accept();
            }
        });

        $this->protocol->on(ListEvent::class, function (ListEvent $event): void {
            $handlerName = $event->isLsub() ? 'LSUB' : 'LIST';
            $handler = $this->handler->getHandler($handlerName) ?? $this->handler->getHandler('LIST');
            if ($handler === null) {
                $event->accept();
                return;
            }

            try {
                $handler($event, $this->context);
                if ($event->isAccepted()) {
                    $result = $event->getResult();
                    if (is_array($result)) {
                        foreach ($result as $folder) {
                            if ($folder instanceof MailboxInfo) {
                                $this->pendingData[] = $event->isLsub()
                                    ? $this->rb->lsub($folder)
                                    : $this->rb->list($folder);
                            }
                        }
                    }
                }
            } catch (\Throwable $e) {
                if (!$event->isHandled()) {
                    $event->reject($e->getMessage());
                }
            }
        });

        $this->protocol->on(StatusEvent::class, function (StatusEvent $event): void {
            $this->dispatchWithResults('STATUS', $event, function (mixed $result): void {
                if ($result instanceof StatusInfo) {
                    $this->pendingData[] = $this->rb->status($result);
                }
            });
        });

        $this->protocol->on(MailboxEvent::class, fn(MailboxEvent $e) => $this->dispatch($e->command->name, $e));
        $this->protocol->on(RenameEvent::class, fn(RenameEvent $e) => $this->dispatch('RENAME', $e));

        $this->protocol->on(IdEvent::class, function (IdEvent $event): void {
            $this->dispatchWithResults('ID', $event, function (mixed $result): void {
                if (is_array($result) && $result !== []) {
                    /** @var array<string, string> $result */
                    $this->pendingData[] = $this->rb->id($result);
                }
            });
        });

        $this->protocol->on(UidExpungeEvent::class, function (UidExpungeEvent $event): void {
            $this->dispatchWithResults('UID EXPUNGE', $event, function (mixed $result): void {
                $this->reportExpunged($result);
            });
        });

        $this->protocol->on(CompressEvent::class, function (CompressEvent $event): void {
            $handler = $this->handler->getHandler('COMPRESS');
            if ($handler === null) {
                $event->reject('Compression not supported');
                return;
            }

            try {
                $handler($event, $this->context);
                if ($event->isAccepted()) {
                    $this->context->session()->enableCompression();
                }
            } catch (\Throwable $e) {
                if (!$event->isHandled()) {
                    $event->reject($e->getMessage());
                }
            }
        });

        $this->protocol->on(QuotaEvent::class, fn(QuotaEvent $e) => $this->dispatch($e->command->name, $e));

        $this->protocol->on(AclEvent::class, function (AclEvent $event): void {
            $handler = $this->handler->getHandler($event->command->name);
            if ($handler === null) {
                $event->accept();
                return;
            }

            try {
                $handler($event, $this->context);
                if ($event->isAccepted()) {
                    $result = $event->getResult();
                    if (is_string($result)) {
                        $name = $event->command->name;
                        if ($name === 'GETACL') {
                            $this->pendingData[] = '* ACL ' . $event->mailbox() . ' ' . $result . "\r\n";
                        } elseif ($name === 'MYRIGHTS') {
                            $this->pendingData[] = '* MYRIGHTS ' . $event->mailbox() . ' ' . $result . "\r\n";
                        } elseif ($name === 'LISTRIGHTS') {
                            $this->pendingData[] = '* LISTRIGHTS ' . $event->mailbox() . ' ' . ($event->identifier() ?? '') . ' ' . $result . "\r\n";
                        }
                    }
                }
            } catch (\Throwable $e) {
                if (!$event->isHandled()) {
                    $event->reject($e->getMessage());
                }
            }
        });

        $this->protocol->on(MetadataEvent::class, function (MetadataEvent $event): void {
            $handler = $this->handler->getHandler($event->command->name);
            if ($handler === null) {
                $event->accept();
                return;
            }

            try {
                $handler($event, $this->context);
                if ($event->isAccepted() && $event->command->name === 'GETMETADATA') {
                    $result = $event->getResult();
                    if (is_array($result) && $result !== []) {
                        $items = [];
                        /** @var array<string, string> $result */
                        foreach ($result as $entry => $value) {
                            $items[] = '"' . $entry . '" "' . $value . '"';
                        }
                        $this->pendingData[] = '* METADATA ' . $event->mailbox() . ' (' . implode(' ', $items) . ")\r\n";
                    }
                }
            } catch (\Throwable $e) {
                if (!$event->isHandled()) {
                    $event->reject($e->getMessage());
                }
            }
        });

        $this->protocol->on(SimpleEvent::class, function (SimpleEvent $event): void {
            $name = strtoupper($event->command->name);

            if ($name === 'EXPUNGE') {
                $this->dispatchWithResults('EXPUNGE', $event, function (mixed $result): void {
                    $this->reportExpunged($result);
                });
                return;
            }

            if ($name === 'CLOSE') {
                $handler = $this->handler->getHandler('CLOSE');
                if ($handler !== null) {
                    try {
                        $handler($event, $this->context);
                    } catch (\Throwable $e) {
                        $event->reject($e->getMessage());
                        return;
                    }
                }
                $this->deselect();
                if (!$event->isHandled()) {
                    $event->accept();
                }
                return;
            }

            if ($name === 'UNSELECT') {
                $this->deselect();
                $event->accept();
                return;
            }

            if ($name === 'CAPABILITY') {

                $this->dispatch('CAPABILITY', $event);
                return;
            }

            if ($name === 'NAMESPACE') {
                $this->dispatchWithResults('NAMESPACE', $event, function (mixed $result): void {
                    if ($result instanceof NamespaceSet) {
                        $this->pendingData[] = $this->rb->namespace($result);
                    }
                });
                return;
            }

            $handler = $this->handler->getHandler($name);
            if ($handler !== null) {
                try {
                    $handler($event, $this->context);
                } catch (\Throwable $e) {
                    if (!$event->isHandled()) {
                        $event->reject($e->getMessage());
                    }
                    return;
                }
            }
            if (!$event->isHandled()) {
                $event->accept();
            }
        });
    }

    private function dispatch(string $name, Event $event, callable|null $onAccept = null): void
    {
        $handler = $this->handler->getHandler($name);
        if ($handler === null) {
            $event->accept();
            return;
        }

        try {
            $handler($event, $this->context);
            if ($event->isAccepted() && $onAccept !== null) {
                $onAccept();
            }
        } catch (\Throwable $e) {
            if (!$event->isHandled()) {
                $event->reject($e->getMessage());
            }
        }
    }

    private function dispatchWithResults(string $name, Event $event, callable $processResults): void
    {
        $handler = $this->handler->getHandler($name);
        if ($handler === null) {
            $event->accept();
            return;
        }

        try {
            $handler($event, $this->context);
            if ($event->isAccepted()) {
                $processResults($event->getResult());
            }
        } catch (\Throwable $e) {
            if (!$event->isHandled()) {
                $event->reject($e->getMessage());
            }
        }
    }
}
