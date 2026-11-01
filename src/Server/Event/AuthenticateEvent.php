<?php

declare(strict_types=1);

/**
 * Server event for IMAP AUTHENTICATE with multi-step SASL support.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Mail\IMAP\Server\Event;

use PHPdot\Mail\IMAP\Auth\SaslToken;
use PHPdot\Mail\IMAP\Exception\InvalidArgumentException;
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

    private string|null $clientResponse = null;

    public function __construct(
        public readonly AuthenticateCommand $authenticateCommand,
    ) {
        parent::__construct($authenticateCommand);
    }

    public function mechanism(): string
    {
        return $this->authenticateCommand->mechanism;
    }

    public function initialResponse(): string|null
    {
        return $this->authenticateCommand->initialResponse;
    }

    /**
     * The client's SASL response (base64): the initial response when one was
     * sent with the command (SASL-IR), otherwise the answer to the challenge.
     */
    public function response(): string|null
    {
        return $this->clientResponse ?? $this->authenticateCommand->initialResponse;
    }

    /**
     * Decoded credentials of a PLAIN exchange, or null when the mechanism is
     * not PLAIN, no response has arrived yet, or the token is malformed.
     *
     * @return array{authzid: string, username: string, password: string}|null
     */
    public function plainCredentials(): array|null
    {
        $response = $this->response();
        if ($response === null || strtoupper($this->mechanism()) !== 'PLAIN') {
            return null;
        }

        try {
            return SaslToken::decodePlain($response);
        } catch (InvalidArgumentException) {
            return null;
        }
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
        $this->clientResponse = $response;
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
