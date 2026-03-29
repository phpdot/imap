<?php
/**
 * Server event for IMAP AUTHENTICATE with multi-step SASL support.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

declare(strict_types=1);

namespace PHPdot\Mail\IMAP\Server\Event;

use PHPdot\Mail\IMAP\Server\Command\AuthenticateCommand;

/**
 * Event for AUTHENTICATE command with multi-step SASL support.
 *
 * Usage for single-step (SASL-IR):
 *   $event->initialResponse() contains the base64 token
 *   $event->accept() or $event->reject()
 *
 * Usage for multi-step:
 *   $event->challenge('base64-challenge') — sends + to client, waits for response
 *   Consumer processes the client response via onChallengeResponse callback
 *   $event->accept() or $event->reject()
 */
class AuthenticateEvent extends Event
{
    /** @var list<string> */
    private array $pendingChallenges = [];

    /** @var (callable(string): void)|null */
    private mixed $challengeResponseHandler = null;

    public function __construct(
        public readonly AuthenticateCommand $authenticateCommand,
    ) {
        parent::__construct($authenticateCommand);
    }

    public function mechanism(): string
    {
        return $this->authenticateCommand->mechanism;
    }

    public function initialResponse(): ?string
    {
        return $this->authenticateCommand->initialResponse;
    }

    /**
     * Send a SASL challenge to the client (server sends "+").
     * The challenge string will be sent as-is (should be base64-encoded by the caller).
     */
    public function challenge(string $challenge = ''): void
    {
        $this->pendingChallenges[] = $challenge;
    }

    /**
     * Register a handler for when the client sends a challenge response.
     *
     * @param callable(string): void $handler Receives the client's base64 response
     */
    public function onChallengeResponse(callable $handler): void
    {
        $this->challengeResponseHandler = $handler;
    }

    /**
     * Called by the protocol layer when the client sends a response to a challenge.
     */
    public function handleChallengeResponse(string $response): void
    {
        if ($this->challengeResponseHandler !== null) {
            ($this->challengeResponseHandler)($response);
        }
    }

    /**
     * Check if there are pending challenges to send.
     */
    public function hasPendingChallenges(): bool
    {
        return $this->pendingChallenges !== [];
    }

    /**
     * Get and clear pending challenges.
     *
     * @return list<string>
     */
    public function drainChallenges(): array
    {
        $challenges = $this->pendingChallenges;
        $this->pendingChallenges = [];
        return $challenges;
    }

    /**
     * Whether a challenge response handler is registered.
     */
    public function expectsChallengeResponse(): bool
    {
        return $this->challengeResponseHandler !== null;
    }
}
