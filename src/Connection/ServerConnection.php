<?php
/**
 * Per-connection server handler. Wraps the protocol engine with the developer's ImapHandler.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

declare(strict_types=1);

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

    /** @var list<string> */
    private array $pendingData = [];

    public function __construct(
        private readonly ImapHandler $handler,
        private readonly ?NotifierInterface $notifier = null,
        ?string $remoteAddress = null,
    ) {
        $this->context = new ConnectionContext($remoteAddress ?? '127.0.0.1');
        $this->protocol = new ServerProtocol();
        $this->rb = new ResponseBuilder();
        $this->registerHandlers();
    }

    public function greeting(string $text = 'IMAP4rev2 Server ready'): string
    {
        return $this->protocol->greeting(null, $text);
    }

    /** @return list<string> */
    public function onData(string $bytes): array
    {
        $this->pendingData = [];
        $rawResponses = $this->protocol->onData($bytes);
        $pending = $this->drainPendingData();

        if ($pending === []) {
            return $rawResponses;
        }

        return array_merge($pending, $rawResponses);
    }

    /** @return list<string> */
    private function drainPendingData(): array
    {
        $data = $this->pendingData;
        $this->pendingData = [];
        return $data;
    }

    public function onClose(): void
    {
        if ($this->notifier !== null && $this->context->selectedMailbox() !== null) {
            $this->notifier->unsubscribe(
                $this->context->selectedMailbox(),
                $this->context->connectionId(),
            );
        }
    }

    public function pushExists(int $count): string
    {
        return $this->protocol->pushExists($count);
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

    public function selectedMailbox(): ?string
    {
        return $this->context->selectedMailbox();
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
                    $handler($event, $this->context);
                    if ($event->isAccepted()) {
                        $this->context->setUser('authenticated');
                    }
                });
                return;
            }

            $handler($event, $this->context);
            if ($event->isAccepted()) {
                $this->context->setUser('authenticated');
            }
        });

        $this->protocol->on(SelectEvent::class, function (SelectEvent $event): void {
            $handler = $this->handler->getHandler('SELECT');
            if ($handler === null) {
                $event->reject('SELECT not implemented');
                return;
            }

            if ($this->notifier !== null && $this->context->selectedMailbox() !== null) {
                $this->notifier->unsubscribe(
                    $this->context->selectedMailbox(),
                    $this->context->connectionId(),
                );
            }

            try {
                $handler($event, $this->context);

                if (!$event->isAccepted()) {
                    return;
                }

                $result = $event->getResult();
                if ($result instanceof SelectResult) {
                    $this->context->setSelectedMailbox($event->mailbox()->name);
                    $this->pendingData[] = $this->rb->exists($result->exists);
                    if ($result->recent > 0) {
                        $this->pendingData[] = $this->rb->recent($result->recent);
                    }
                    if ($result->flags !== []) {
                        $this->pendingData[] = $this->rb->flags($result->flags);
                    }
                    if ($result->permanentFlags !== []) {
                        $this->pendingData[] = $this->rb->okPermanentFlags($result->permanentFlags);
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

                    if ($this->notifier !== null) {
                        $this->notifier->subscribe(
                            $event->mailbox()->name,
                            $this->context->connectionId(),
                            function (MailboxChange $change): void {},
                        );
                    }
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
                }
            });
        });

        $this->protocol->on(SearchEvent::class, function (SearchEvent $event): void {
            $this->dispatchWithResults('SEARCH', $event, function (mixed $results): void {
                if (is_array($results)) {
                    /** @var list<int> $results */
                    $this->pendingData[] = $this->rb->search($results);
                }
            });
        });

        $this->protocol->on(StoreEvent::class, function (StoreEvent $event): void {
            $this->dispatchWithResults('STORE', $event, function (mixed $result) use ($event): void {
                if (!$event->isSilent() && is_array($result)) {
                    foreach ($result as $entry) {
                        if (is_array($entry) && isset($entry['seq'], $entry['flags']) && is_int($entry['seq'])) {
                            $seq = $entry['seq'];
                            /** @var list<string> $flags */
                            $flags = $entry['flags'];
                            $flagStr = implode(' ', $flags);
                            $line = '* ' . $seq . ' FETCH (FLAGS (' . $flagStr . '))';
                            if (isset($entry['uid']) && is_int($entry['uid'])) {
                                $line = '* ' . $seq . ' FETCH (UID ' . $entry['uid'] . ' FLAGS (' . $flagStr . '))';
                            }
                            $this->pendingData[] = $line . "\r\n";
                        }
                    }
                }
            });
        });

        $this->protocol->on(CopyEvent::class, fn(CopyEvent $e) => $this->dispatch('COPY', $e));
        $this->protocol->on(MoveEvent::class, fn(MoveEvent $e) => $this->dispatch('MOVE', $e));
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
                                $this->pendingData[] = $this->rb->list($folder);
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
                if (is_array($result)) {
                    foreach ($result as $seq) {
                        if (is_int($seq)) {
                            $this->pendingData[] = $this->rb->expunge($seq);
                        }
                    }
                }
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

        // SimpleEvent: CAPABILITY, NOOP, LOGOUT, CLOSE, UNSELECT, EXPUNGE, NAMESPACE
        $this->protocol->on(SimpleEvent::class, function (SimpleEvent $event): void {
            $name = strtoupper($event->command->name);

            if ($name === 'EXPUNGE') {
                $this->dispatchWithResults('EXPUNGE', $event, function (mixed $result): void {
                    if (is_array($result)) {
                        foreach ($result as $seq) {
                            if (is_int($seq)) {
                                $this->pendingData[] = $this->rb->expunge($seq);
                            }
                        }
                    }
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
                if ($this->notifier !== null && $this->context->selectedMailbox() !== null) {
                    $this->notifier->unsubscribe(
                        $this->context->selectedMailbox(),
                        $this->context->connectionId(),
                    );
                }
                $this->context->setSelectedMailbox(null);
                if (!$event->isHandled()) {
                    $event->accept();
                }
                return;
            }

            if ($name === 'UNSELECT') {
                if ($this->notifier !== null && $this->context->selectedMailbox() !== null) {
                    $this->notifier->unsubscribe(
                        $this->context->selectedMailbox(),
                        $this->context->connectionId(),
                    );
                }
                $this->context->setSelectedMailbox(null);
                $event->accept();
                return;
            }

            if ($name === 'CAPABILITY') {
                $this->dispatchWithResults('CAPABILITY', $event, function (mixed $result): void {
                    if ($result instanceof CapabilitySet) {
                        $this->pendingData[] = $this->rb->capability($result);
                    }
                });
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

    private function dispatch(string $name, Event $event, ?callable $onAccept = null): void
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
