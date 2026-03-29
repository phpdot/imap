<?php
/**
 * Manages IMAP connection state transitions.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

declare(strict_types=1);

namespace PHPdot\Mail\IMAP\Protocol\StateMachine;

use PHPdot\Mail\IMAP\DataType\Enum\CommandGroup;
use PHPdot\Mail\IMAP\DataType\Enum\ConnectionState;
use PHPdot\Mail\IMAP\Exception\StateException;

/**
 * Manages IMAP connection state transitions.
 *
 * States: NotAuthenticated → Authenticated → Selected → Logout
 */
final class StateMachine
{
    private ConnectionState $state;
    private readonly CommandRegistry $registry;

    public function __construct(
        ConnectionState $initialState = ConnectionState::NotAuthenticated,
        ?CommandRegistry $registry = null,
    ) {
        $this->state = $initialState;
        $this->registry = $registry ?? new CommandRegistry();
    }

    public function state(): ConnectionState
    {
        return $this->state;
    }

    public function isCommandAllowed(string $command): bool
    {
        $group = $this->registry->getGroup($command);
        if ($group === null) {
            return false;
        }
        return $group->isAllowedIn($this->state);
    }

    /**
     * @throws StateException
     */
    public function assertCommandAllowed(string $command): void
    {
        if ($this->state === ConnectionState::Logout) {
            throw new StateException(
                sprintf('Connection is in Logout state, no commands accepted'),
            );
        }

        $group = $this->registry->getGroup($command);
        if ($group === null) {
            throw new StateException(
                sprintf('Unknown command: %s', $command),
            );
        }

        if (!$group->isAllowedIn($this->state)) {
            throw new StateException(
                sprintf(
                    'Command %s is not allowed in %s state (requires %s)',
                    $command,
                    $this->state->value,
                    $group->value,
                ),
            );
        }
    }

    /**
     * Transitions to Authenticated state after successful LOGIN/AUTHENTICATE.
     */
    public function authenticated(): void
    {
        $this->state = ConnectionState::Authenticated;
    }

    /**
     * Transitions to Selected state after successful SELECT/EXAMINE.
     */
    public function selected(): void
    {
        $this->state = ConnectionState::Selected;
    }

    /**
     * Transitions back to Authenticated after CLOSE/UNSELECT or failed SELECT.
     */
    public function deselected(): void
    {
        $this->state = ConnectionState::Authenticated;
    }

    /**
     * Transitions to Logout state.
     */
    public function logout(): void
    {
        $this->state = ConnectionState::Logout;
    }

    /**
     * Applies the state transition for a completed command.
     */
    public function applySuccessTransition(string $command): void
    {
        $upper = strtoupper(trim($command));

        match ($upper) {
            'LOGIN', 'AUTHENTICATE', 'AUTHENTICATE PLAIN',
            'AUTHENTICATE PLAIN-CLIENTTOKEN', 'AUTHENTICATE XOAUTH2',
            'AUTHENTICATE OAUTHBEARER' => $this->authenticated(),
            'SELECT', 'EXAMINE' => $this->selected(),
            'CLOSE', 'UNSELECT' => $this->deselected(),
            'LOGOUT' => $this->logout(),
            default => null,
        };
    }

    public function registry(): CommandRegistry
    {
        return $this->registry;
    }
}
