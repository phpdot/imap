<?php
/**
 * Client-side IMAP protocol engine. Parses, accumulates, and emits events.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

declare(strict_types=1);

namespace PHPdot\Mail\IMAP\Client;

use PHPdot\Mail\IMAP\Client\Command\CommandBuilder;
use PHPdot\Mail\IMAP\Client\Event\ClientEvent;
use PHPdot\Mail\IMAP\Client\Event\ContinuationEvent;
use PHPdot\Mail\IMAP\Client\Event\DataEvent;
use PHPdot\Mail\IMAP\Client\Event\GreetingEvent;
use PHPdot\Mail\IMAP\Client\Event\TaggedResponseEvent;
use PHPdot\Mail\IMAP\Client\Parser\ResponseInterpreter;
use PHPdot\Mail\IMAP\Client\Parser\ResponseParser;
use PHPdot\Mail\IMAP\Client\Response\ContinuationResponse;
use PHPdot\Mail\IMAP\Client\Response\DataResponse;
use PHPdot\Mail\IMAP\Client\Response\GreetingResponse;
use PHPdot\Mail\IMAP\Client\Response\TaggedResponse;
use PHPdot\Mail\IMAP\DataType\DTO\FetchResult;
use PHPdot\Mail\IMAP\DataType\Enum\ResponseCode;
use PHPdot\Mail\IMAP\DataType\ValueObject\CapabilitySet;
use PHPdot\Mail\IMAP\DataType\ValueObject\Flag;
use PHPdot\Mail\IMAP\DataType\ValueObject\Tag;
use PHPdot\Mail\IMAP\Protocol\LineParser;
use PHPdot\Mail\IMAP\Protocol\Session;
use PHPdot\Mail\IMAP\Result\IdleNotification;

/**
 * Client-side IMAP protocol engine.
 *
 * Parses server bytes, accumulates unsolicited responses, tracks protocol state,
 * and emits events. Used by ImapClient (synchronous) and can be used directly
 * for non-blocking multiplexed clients.
 *
 * Usage (event-driven):
 *   $protocol = new ClientProtocol();
 *   $protocol->on(DataEvent::class, function (DataEvent $e) { ... });
 *   $protocol->onData($serverBytes);
 *
 * Usage (synchronous via ImapClient):
 *   $protocol->onData($bytes);
 *   $tagged = $protocol->consumeTaggedResponse($tag);
 *   $results = $protocol->drainFetchResults();
 */
final class ClientProtocol
{
    private readonly LineParser $lineParser;
    private readonly ResponseParser $responseParser;
    private readonly ResponseInterpreter $interpreter;
    private readonly ClientEventEmitter $emitter;
    private readonly CommandBuilder $commandBuilder;
    private readonly Session $session;
    private bool $serverHasRev2 = false;

    // Greeting
    private ?GreetingResponse $greeting = null;

    // Buffered responses for synchronous consumption
    private ?TaggedResponse $lastTaggedResponse = null;
    private ?ContinuationResponse $lastContinuation = null;

    // Unsolicited response accumulators
    private ?int $lastExists = null;
    private int $lastRecent = 0;
    /** @var list<Flag> */
    private array $lastFlags = [];
    /** @var list<Flag> */
    private array $lastPermanentFlags = [];
    private ?int $lastUidValidity = null;
    private ?int $lastUidNext = null;
    private ?int $lastHighestModseq = null;
    /** @var list<int> */
    private array $pendingExpunges = [];
    /** @var list<FetchResult> */
    private array $pendingFetchResults = [];
    /** @var list<DataResponse> */
    private array $pendingUnsolicitedResponses = [];

    public function __construct(?Session $session = null)
    {
        $this->lineParser = new LineParser();
        $this->responseParser = new ResponseParser();
        $this->interpreter = new ResponseInterpreter();
        $this->emitter = new ClientEventEmitter();
        $this->commandBuilder = new CommandBuilder();
        $this->session = $session ?? new Session();
    }

    // === EVENT REGISTRATION ===

    /**
     * @template T of ClientEvent
     * @param class-string<T> $eventClass
     * @param callable(T): void $handler
     */
    public function on(string $eventClass, callable $handler): void
    {
        $this->emitter->on($eventClass, $handler);
    }

    // === DATA PROCESSING ===

    /**
     * Feed incoming server bytes. Parses, accumulates, and emits events.
     */
    public function onData(string $bytes): void
    {
        $lines = $this->lineParser->feed($bytes);

        foreach ($lines as $line) {
            $response = $this->responseParser->parse($line);

            if ($response instanceof GreetingResponse) {
                $this->processGreeting($response);
                $this->emitter->emit(new GreetingEvent($response));
            } elseif ($response instanceof TaggedResponse) {
                $this->lastTaggedResponse = $response;
                $this->emitter->emit(new TaggedResponseEvent($response));
            } elseif ($response instanceof ContinuationResponse) {
                $this->lastContinuation = $response;
                $this->emitter->emit(new ContinuationEvent($response));
            } elseif ($response instanceof DataResponse) {
                $this->processDataResponse($response);
                $this->emitter->emit(new DataEvent($response));
            }
        }
    }

    // === SYNCHRONOUS CONSUMPTION ===

    /**
     * Poll for a tagged response matching the expected tag.
     * Returns null if not yet available (call onData with more bytes).
     */
    public function consumeTaggedResponse(Tag $expectedTag): ?TaggedResponse
    {
        if ($this->lastTaggedResponse !== null && $this->lastTaggedResponse->tag->equals($expectedTag)) {
            $result = $this->lastTaggedResponse;
            $this->lastTaggedResponse = null;
            return $result;
        }
        return null;
    }

    /**
     * Poll for a continuation response.
     */
    public function consumeContinuation(): ?ContinuationResponse
    {
        $result = $this->lastContinuation;
        $this->lastContinuation = null;
        return $result;
    }

    // === ACCESSORS ===

    public function command(): CommandBuilder
    {
        return $this->commandBuilder;
    }

    public function session(): Session
    {
        return $this->session;
    }

    public function interpreter(): ResponseInterpreter
    {
        return $this->interpreter;
    }

    public function greeting(): ?GreetingResponse
    {
        return $this->greeting;
    }

    public function serverSupportsRev2(): bool
    {
        return $this->serverHasRev2;
    }

    public function lastExists(): ?int
    {
        return $this->lastExists;
    }

    public function lastRecent(): int
    {
        return $this->lastRecent;
    }

    /** @return list<Flag> */
    public function lastFlags(): array
    {
        return $this->lastFlags;
    }

    /** @return list<Flag> */
    public function lastPermanentFlags(): array
    {
        return $this->lastPermanentFlags;
    }

    public function lastUidValidity(): ?int
    {
        return $this->lastUidValidity;
    }

    public function lastUidNext(): ?int
    {
        return $this->lastUidNext;
    }

    public function lastHighestModseq(): ?int
    {
        return $this->lastHighestModseq;
    }

    // === DRAIN METHODS ===

    /** @return list<FetchResult> */
    public function drainFetchResults(): array
    {
        $results = $this->pendingFetchResults;
        $this->pendingFetchResults = [];
        return $results;
    }

    /** @return list<int> */
    public function drainExpunges(): array
    {
        $expunges = $this->pendingExpunges;
        $this->pendingExpunges = [];
        return $expunges;
    }

    /** @return list<DataResponse> */
    public function drainUnsolicitedByType(string $type): array
    {
        $matching = [];
        $remaining = [];
        foreach ($this->pendingUnsolicitedResponses as $response) {
            if (strtoupper($response->type) === strtoupper($type)) {
                $matching[] = $response;
            } else {
                $remaining[] = $response;
            }
        }
        $this->pendingUnsolicitedResponses = $remaining;
        return $matching;
    }

    /** @return list<DataResponse> */
    public function drainAllUnsolicited(): array
    {
        $all = $this->pendingUnsolicitedResponses;
        $this->pendingUnsolicitedResponses = [];
        return $all;
    }

    // === STATE MANAGEMENT ===

    /**
     * Clear accumulators before SELECT/EXAMINE.
     */
    public function resetSelectState(): void
    {
        $this->lastExists = null;
        $this->lastRecent = 0;
        $this->lastFlags = [];
        $this->lastPermanentFlags = [];
        $this->lastUidValidity = null;
        $this->lastUidNext = null;
        $this->lastHighestModseq = null;
    }

    /**
     * Reset the line parser (for reconnect).
     */
    public function resetParser(): void
    {
        $this->lineParser->reset();
    }

    // === IDLE SUPPORT ===

    public function buildIdleNotification(DataResponse $response): IdleNotification
    {
        if ($response->isExists()) {
            return new IdleNotification('exists', $response->number);
        }
        if ($response->isExpunge()) {
            return new IdleNotification('expunge', $response->number);
        }
        if ($response->isFetch()) {
            $fetchResult = $this->interpreter->interpretFetch($response);
            return new IdleNotification('fetch', $fetchResult->sequenceNumber, $fetchResult->flags);
        }
        return new IdleNotification(strtolower($response->type), $response->number);
    }

    // === INTERNAL ===

    private function processGreeting(GreetingResponse $greeting): void
    {
        $this->greeting = $greeting;
        if ($greeting->responseText->code === ResponseCode::Capability) {
            $this->session->setCapabilities(
                CapabilitySet::fromArray($greeting->responseText->codeData),
            );
            $this->detectCapabilities($greeting->responseText->codeData);
        }
    }

    private function processDataResponse(DataResponse $response): void
    {
        if ($response->isExists()) {
            $this->lastExists = $response->number;
        } elseif ($response->isRecent()) {
            $this->lastRecent = $response->number ?? 0;
        } elseif ($response->isExpunge()) {
            if ($response->number !== null) {
                $this->pendingExpunges[] = $response->number;
            }
        } elseif ($response->isFetch()) {
            $this->pendingFetchResults[] = $this->interpreter->interpretFetch($response);
        } elseif ($response->isFlags()) {
            $this->lastFlags = $this->interpreter->interpretFlags($response);
        } elseif ($response->isOk()) {
            $this->handleOkResponse($response);
        } elseif ($response->isCapability()) {
            $this->session->setCapabilities($this->interpreter->interpretCapability($response));
            $caps = array_map(
                static fn($t) => strtoupper($t->stringValue()),
                $response->tokens,
            );
            $this->detectCapabilities($caps);
        }

        if ($response->isEnabled()) {
            foreach ($response->tokens as $token) {
                $cap = strtoupper($token->stringValue());
                match ($cap) {
                    'IMAP4REV2' => $this->session->enableRev2(),
                    'CONDSTORE' => $this->session->enableCondstore(),
                    'UTF8=ACCEPT' => $this->session->enableUtf8(),
                    default => null,
                };
            }
        }

        $this->pendingUnsolicitedResponses[] = $response;
    }

    private function handleOkResponse(DataResponse $response): void
    {
        foreach ($response->tokens as $token) {
            if ($token->isSection()) {
                $content = $token->stringValue();
                $upper = strtoupper($content);

                if (preg_match('/^(UIDVALIDITY|UIDNEXT|HIGHESTMODSEQ)\s+(\d+)/i', $content, $m) === 1) {
                    $val = (int) $m[2];
                    match (strtoupper($m[1])) {
                        'UIDVALIDITY' => $this->lastUidValidity = $val,
                        'UIDNEXT' => $this->lastUidNext = $val,
                        'HIGHESTMODSEQ' => $this->lastHighestModseq = $val,
                        default => null,
                    };
                }

                if (str_starts_with($upper, 'PERMANENTFLAGS')) {
                    $this->lastPermanentFlags = [];
                    if (preg_match('/\(([^)]*)\)/', $content, $fm) === 1) {
                        $flagStrs = preg_split('/\s+/', trim($fm[1]));
                        if ($flagStrs !== false) {
                            foreach ($flagStrs as $f) {
                                $f = trim($f);
                                if ($f !== '' && $f !== '\\*') {
                                    $this->lastPermanentFlags[] = new Flag($f);
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    /** @param list<string> $capabilities */
    private function detectCapabilities(array $capabilities): void
    {
        foreach ($capabilities as $cap) {
            if (strtoupper($cap) === 'IMAP4REV2') {
                $this->serverHasRev2 = true;
                break;
            }
        }
    }
}
