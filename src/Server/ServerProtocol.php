<?php

declare(strict_types=1);

/**
 * Orchestrates the full server-side IMAP protocol pipeline.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Mail\IMAP\Server;

use PHPdot\Mail\IMAP\DataType\Enum\ConnectionState;
use PHPdot\Mail\IMAP\DataType\ValueObject\CapabilitySet;
use PHPdot\Mail\IMAP\DataType\ValueObject\Tag;
use PHPdot\Mail\IMAP\Exception\InvalidArgumentException;
use PHPdot\Mail\IMAP\Exception\ParseException;
use PHPdot\Mail\IMAP\Protocol\LineParser;
use PHPdot\Mail\IMAP\Protocol\Session;
use PHPdot\Mail\IMAP\Protocol\StateMachine\StateMachine;
use PHPdot\Mail\IMAP\Server\Event\AuthenticateEvent;
use PHPdot\Mail\IMAP\Server\Event\Event;
use PHPdot\Mail\IMAP\Server\Event\IdleEvent;
use PHPdot\Mail\IMAP\Server\Parser\CommandParser;
use PHPdot\Mail\IMAP\Server\Response\ResponseBuilder;
use ValueError;

/**
 * Orchestrates the full server-side IMAP protocol pipeline.
 *
 * Handles:
 * - Protocol negotiation (rev1/rev2, CONDSTORE, UTF8=ACCEPT)
 * - IDLE mode (routes DONE back to IdleEvent)
 * - SASL multi-step authentication (routes challenge responses)
 * - Unsolicited responses (pushNotification)
 */
final class ServerProtocol
{
    private readonly LineParser $lineParser;
    private readonly CommandParser $commandParser;
    private readonly CommandDispatcher $dispatcher;
    private readonly ResponseBuilder $responseBuilder;
    private readonly EventEmitter $emitter;
    private readonly StateMachine $stateMachine;
    private readonly Session $session;
    private int $badCommandCount = 0;
    private const int MAX_BAD_COMMANDS = 50;

    private IdleEvent|null $activeIdle = null;

    /** @var (\Closure(list<string>, null|string): list<string|\Iterator<mixed, string>>)|null */
    private \Closure|null $responseInterceptor = null;

    private string|null $lastCommandName = null;

    private int $linesProcessed = 0;

    /** @var list<string> Complete command lines not yet processed. */
    private array $backlog = [];
    private AuthenticateEvent|null $activeAuth = null;

    public function __construct(
        StateMachine|null $stateMachine = null,
        Session|null $session = null,
        int $maxLiteralBytes = 52428800,
    ) {
        $this->lineParser = new LineParser($maxLiteralBytes);
        $this->commandParser = new CommandParser();
        $this->responseBuilder = new ResponseBuilder();
        $this->emitter = new EventEmitter();
        $this->stateMachine = $stateMachine ?? new StateMachine();
        $this->session = $session ?? new Session();
        $this->dispatcher = new CommandDispatcher(
            $this->stateMachine,
            $this->emitter,
            $this->responseBuilder,
            $this->session,
        );
    }

    /**
     * Register an event handler.
     *
     * @template T of Event
     *
     * @param class-string<T> $eventClass
     * @param callable(T): void $handler
     */
    public function on(string $eventClass, callable $handler): void
    {
        $this->emitter->on($eventClass, $handler);
    }

    /**
     * Generate the server greeting.
     */
    public function greeting(CapabilitySet|null $capabilities = null, string $text = 'Server ready'): string
    {
        if ($capabilities !== null) {
            return $this->responseBuilder->greetingWithCapability($capabilities, $text);
        }
        return $this->responseBuilder->greeting($text);
    }

    /**
     * Feed incoming bytes from the client and return response lines.
     *
     * @return list<string>
     */
    public function onData(string $bytes): array
    {
        $out = [];
        $responses = $this->onDataDeferred($bytes);

        while (true) {
            foreach ($responses as $response) {
                if (is_string($response)) {
                    $out[] = $response;
                    continue;
                }
                foreach ($response as $chunk) {
                    $out[] = $chunk;
                }
            }

            if ($this->backlog === []) {
                return $out;
            }
            $responses = $this->processBacklog();
        }
    }

    /**
     * Feed incoming bytes from the client and return the responses, in order.
     *
     * Each entry is wire bytes, or — when a response interceptor supplied
     * one — an iterator that yields wire bytes lazily (large FETCH results).
     *
     * @return list<string|\Iterator<mixed, string>>
     */
    public function onDataDeferred(string $bytes): array
    {
        $lines = $this->lineParser->feed($bytes);
        $this->linesProcessed += count($lines);
        foreach ($lines as $line) {
            $this->backlog[] = $line;
        }

        $responses = $this->processBacklog();

        foreach ($this->lineParser->drainRejections() as $rejection) {
            $responses[] = ($rejection['tag'] === '' ? '*' : $rejection['tag'])
                . ' BAD ' . $rejection['reason'] . "\r\n";
        }

        if ($this->lineParser->needsContinuation()) {
            $responses[] = $this->responseBuilder->continuation();
        }

        return $responses;
    }

    /**
     * Whether pipelined commands are waiting for a lazily produced response
     * to be consumed. Call resume() once the iterator handed out by
     * onDataDeferred() / resume() is exhausted.
     */
    public function hasBacklog(): bool
    {
        return $this->backlog !== [];
    }

    /**
     * Continue with the pipelined commands that were held back.
     *
     * @return list<string|\Iterator<mixed, string>>
     */
    public function resume(): array
    {
        return $this->processBacklog();
    }

    /**
     * Process buffered command lines in order, stopping after one whose
     * response is produced lazily: the commands behind it must not run —
     * and change the mailbox — before that response has actually been
     * generated, or it would describe the wrong state.
     *
     * @return list<string|\Iterator<mixed, string>>
     */
    private function processBacklog(): array
    {
        $responses = [];

        while ($this->backlog !== []) {
            $line = array_shift($this->backlog);
            $this->lastCommandName = null;
            $lineResponses = $this->processLine($line);
            if ($this->responseInterceptor !== null) {
                $lineResponses = ($this->responseInterceptor)($lineResponses, $this->lastCommandName);
            }

            $lazy = false;
            foreach ($lineResponses as $response) {
                $responses[] = $response;
                $lazy = $lazy || !is_string($response);
            }

            if ($lazy) {
                break;
            }
        }

        return $responses;
    }

    /**
     * Intercept the responses of each processed line before they are
     * returned — the connection layer uses this to place a command's
     * untagged data (and queued mailbox changes) directly before that
     * command's own completion, so pipelined commands stay correctly framed.
     *
     * @param \Closure(list<string>, null|string): list<string|\Iterator<mixed, string>> $interceptor Receives the line's
     *                                                                                                responses and the command name (null for non-command lines: DONE, SASL
     *                                                                                                responses, unparseable input).
     */
    public function setResponseInterceptor(\Closure $interceptor): void
    {
        $this->responseInterceptor = $interceptor;
    }

    /**
     * Total client lines processed on this connection (commands, DONE,
     * SASL responses, malformed input) — the basis for rate limiting.
     */
    public function linesProcessed(): int
    {
        return $this->linesProcessed;
    }

    /**
     * Push an unsolicited response to the client.
     *
     * Use this to send EXISTS, EXPUNGE, FETCH flag changes when the mailbox
     * is modified by another session. Only valid in Selected state.
     *
     * @return string Wire-format response to send to the client
     */
    public function pushNotification(string $untaggedResponse): string
    {
        return '* ' . $untaggedResponse . "\r\n";
    }

    /**
     * Push EXISTS notification.
     */
    public function pushExists(int $count): string
    {
        return $this->responseBuilder->exists($count);
    }

    /**
     * Push EXPUNGE notification.
     */
    public function pushExpunge(int $sequenceNumber): string
    {
        return $this->responseBuilder->expunge($sequenceNumber);
    }

    /**
     * Push flag change notification.
     *
     * @param list<\PHPdot\Mail\IMAP\DataType\ValueObject\Flag> $flags
     */
    public function pushFlagUpdate(int $sequenceNumber, int $uid, array $flags): string
    {
        $flagStrs = array_map(static fn(\PHPdot\Mail\IMAP\DataType\ValueObject\Flag $f): string => $f->value, $flags);
        return '* ' . $sequenceNumber . ' FETCH (UID ' . $uid . ' FLAGS (' . implode(' ', $flagStrs) . "))\r\n";
    }

    /**
     * Whether the connection is in IDLE mode.
     */
    public function isIdling(): bool
    {
        return $this->activeIdle !== null;
    }

    /**
     * Whether the connection is in SASL auth exchange.
     */
    public function isAuthenticating(): bool
    {
        return $this->activeAuth !== null;
    }

    /**
     * @return list<string>
     */
    private function processLine(string $line): array
    {

        if ($this->activeIdle !== null) {
            return $this->processIdleDone($line);
        }

        if ($this->activeAuth !== null) {
            return $this->processAuthResponse($line);
        }

        try {
            $command = $this->commandParser->parse($line);
        } catch (ParseException | InvalidArgumentException | ValueError $e) {
            return $this->badCommand(null, $e->getMessage());
        }

        $this->badCommandCount = 0;
        $this->lastCommandName = $command->name;

        try {
            $responses = $this->dispatcher->dispatch($command);
        } catch (InvalidArgumentException | ValueError $e) {
            return $this->badCommand($command->tag, $e->getMessage());
        }

        if ($command->name === 'IDLE') {
            $this->checkIdleStarted();
        }

        if ($command instanceof \PHPdot\Mail\IMAP\Server\Command\AuthenticateCommand) {
            $this->checkAuthStarted();
        }

        return $responses;
    }

    /**
     * A malformed or unwelcome command is answered, never thrown: the worker
     * must survive anything a client sends before authentication.
     *
     * @return list<string>
     */
    private function badCommand(Tag|null $tag, string $message): array
    {
        $this->badCommandCount++;
        if ($this->badCommandCount >= self::MAX_BAD_COMMANDS) {
            return [
                $this->responseBuilder->bye('Too many invalid commands'),
            ];
        }

        return $tag === null
            ? [$this->responseBuilder->untagged('BAD ' . $message)]
            : [$this->responseBuilder->bad($tag, $message)];
    }

    /**
     * @return list<string>
     */
    private function processIdleDone(string $line): array
    {
        $idle = $this->activeIdle;
        if ($idle === null) {
            return [];
        }
        $this->activeIdle = null;

        if (strtoupper(trim($line)) !== 'DONE') {
            return [$this->responseBuilder->bad(
                $idle->command->tag,
                'Expected DONE',
            )];
        }

        $idle->done();
        $responses = [];

        foreach ($idle->drainNotifications() as $notification) {
            $responses[] = '* ' . $notification . "\r\n";
        }

        $responses[] = $this->responseBuilder->ok($idle->command->tag, 'IDLE terminated');

        return $responses;
    }

    /**
     * @return list<string>
     */
    private function processAuthResponse(string $line): array
    {
        $auth = $this->activeAuth;
        if ($auth === null) {
            return [];
        }

        if (trim($line) === '*') {
            $this->activeAuth = null;
            return [$this->responseBuilder->bad(
                $auth->command->tag,
                'Authentication cancelled',
            )];
        }

        $auth->handleChallengeResponse(trim($line));

        if ($auth->isHandled()) {
            $this->activeAuth = null;
            if ($auth->isAccepted()) {
                $this->stateMachine->applySuccessTransition('AUTHENTICATE');
                return [$this->responseBuilder->ok($auth->command->tag, 'AUTHENTICATE completed')];
            }
            return [$this->responseBuilder->no(
                $auth->command->tag,
                $auth->getRejectText(),
                $auth->getRejectCode(),
            )];
        }

        if ($auth->hasPendingChallenges()) {
            $responses = [];
            foreach ($auth->drainChallenges() as $challenge) {
                $responses[] = $this->responseBuilder->continuation($challenge);
            }
            return $responses;
        }

        return [];
    }

    private function checkIdleStarted(): void
    {

        $lastEvent = $this->dispatcher->lastEvent();
        if ($lastEvent instanceof IdleEvent && $lastEvent->isAccepted()) {
            $this->activeIdle = $lastEvent;
        }
    }

    private function checkAuthStarted(): void
    {
        $lastEvent = $this->dispatcher->lastEvent();
        if ($lastEvent instanceof AuthenticateEvent
            && !$lastEvent->isHandled()
            && $lastEvent->expectsChallengeResponse()) {
            $this->activeAuth = $lastEvent;
        }
    }

    public function state(): ConnectionState
    {
        return $this->stateMachine->state();
    }

    public function session(): Session
    {
        return $this->session;
    }

    public function isLoggedOut(): bool
    {
        return $this->stateMachine->state() === ConnectionState::Logout;
    }
}
