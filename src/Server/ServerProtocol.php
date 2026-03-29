<?php
/**
 * Orchestrates the full server-side IMAP protocol pipeline.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

declare(strict_types=1);

namespace PHPdot\Mail\IMAP\Server;

use PHPdot\Mail\IMAP\DataType\Enum\ConnectionState;
use PHPdot\Mail\IMAP\DataType\ValueObject\CapabilitySet;
use PHPdot\Mail\IMAP\Exception\ParseException;
use PHPdot\Mail\IMAP\Protocol\LineParser;
use PHPdot\Mail\IMAP\Protocol\Session;
use PHPdot\Mail\IMAP\Protocol\StateMachine\StateMachine;
use PHPdot\Mail\IMAP\Server\Event\AuthenticateEvent;
use PHPdot\Mail\IMAP\Server\Event\Event;
use PHPdot\Mail\IMAP\Server\Event\IdleEvent;
use PHPdot\Mail\IMAP\Server\Parser\CommandParser;
use PHPdot\Mail\IMAP\Server\Response\ResponseBuilder;

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

    private ?IdleEvent $activeIdle = null;
    private ?AuthenticateEvent $activeAuth = null;

    public function __construct(
        ?StateMachine $stateMachine = null,
        ?Session $session = null,
    ) {
        $this->lineParser = new LineParser();
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
    public function greeting(?CapabilitySet $capabilities = null, string $text = 'Server ready'): string
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
        $responses = [];
        $lines = $this->lineParser->feed($bytes);

        // Handle literal continuation requests
        if ($this->lineParser->needsContinuation()) {
            $responses[] = $this->responseBuilder->continuation();
        }

        foreach ($lines as $line) {
            $lineResponses = $this->processLine($line);
            foreach ($lineResponses as $response) {
                $responses[] = $response;
            }
        }

        return $responses;
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
        // IDLE mode: "DONE" terminates it
        if ($this->activeIdle !== null) {
            return $this->processIdleDone($line);
        }

        // SASL auth exchange: line is a challenge response
        if ($this->activeAuth !== null) {
            return $this->processAuthResponse($line);
        }

        try {
            $command = $this->commandParser->parse($line);
        } catch (ParseException $e) {
            $this->badCommandCount++;
            if ($this->badCommandCount >= self::MAX_BAD_COMMANDS) {
                return [
                    $this->responseBuilder->bye('Too many invalid commands'),
                ];
            }
            return [
                $this->responseBuilder->untagged('BAD ' . $e->getMessage()),
            ];
        }

        $this->badCommandCount = 0;
        $responses = $this->dispatcher->dispatch($command);

        // Track IDLE state
        if ($command->name === 'IDLE') {
            $this->checkIdleStarted();
        }

        // Track AUTHENTICATE multi-step
        if ($command instanceof \PHPdot\Mail\IMAP\Server\Command\AuthenticateCommand) {
            $this->checkAuthStarted();
        }

        return $responses;
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

        // Flush any pending notifications
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

        // Client cancels with *
        if (trim($line) === '*') {
            $this->activeAuth = null;
            return [$this->responseBuilder->bad(
                $auth->command->tag,
                'Authentication cancelled',
            )];
        }

        // Pass the response to the event handler
        $auth->handleChallengeResponse(trim($line));

        // Check if auth completed
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

        // More challenges to send
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
        // Find the last emitted IdleEvent through the dispatcher
        // The dispatcher creates IdleEvent for IDLE commands — we capture it
        // by checking if the response was a continuation (IDLE accepted)
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
