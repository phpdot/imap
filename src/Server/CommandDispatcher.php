<?php

declare(strict_types=1);

/**
 * Routes parsed IMAP commands to the event system with state validation.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Mail\IMAP\Server;

use PHPdot\Mail\IMAP\DataType\Enum\ConnectionState;
use PHPdot\Mail\IMAP\DataType\Enum\ResponseCode;
use PHPdot\Mail\IMAP\DataType\ValueObject\CapabilitySet;
use PHPdot\Mail\IMAP\DataType\ValueObject\SequenceSet;
use PHPdot\Mail\IMAP\Exception\StateException;
use PHPdot\Mail\IMAP\Protocol\Session;
use PHPdot\Mail\IMAP\Protocol\StateMachine\StateMachine;
use PHPdot\Mail\IMAP\Result\SelectResult;
use PHPdot\Mail\IMAP\Server\Command\AclCommand;
use PHPdot\Mail\IMAP\Server\Command\AppendCommand;
use PHPdot\Mail\IMAP\Server\Command\AuthenticateCommand;
use PHPdot\Mail\IMAP\Server\Command\Command;
use PHPdot\Mail\IMAP\Server\Command\CompressCommand;
use PHPdot\Mail\IMAP\Server\Command\CopyMoveCommand;
use PHPdot\Mail\IMAP\Server\Command\EnableCommand;
use PHPdot\Mail\IMAP\Server\Command\FetchCommand;
use PHPdot\Mail\IMAP\Server\Command\IdCommand;
use PHPdot\Mail\IMAP\Server\Command\ListCommand;
use PHPdot\Mail\IMAP\Server\Command\LoginCommand;
use PHPdot\Mail\IMAP\Server\Command\MailboxCommand;
use PHPdot\Mail\IMAP\Server\Command\MetadataCommand;
use PHPdot\Mail\IMAP\Server\Command\QuotaCommand;
use PHPdot\Mail\IMAP\Server\Command\RenameCommand;
use PHPdot\Mail\IMAP\Server\Command\SearchCommand;
use PHPdot\Mail\IMAP\Server\Command\SelectCommand;
use PHPdot\Mail\IMAP\Server\Command\SimpleCommand;
use PHPdot\Mail\IMAP\Server\Command\StoreCommand;
use PHPdot\Mail\IMAP\Server\Command\UidExpungeCommand;
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

/**
 * Routes parsed commands to the event system. Validates state transitions.
 * Handles protocol-level concerns: ENABLE, SELECT CONDSTORE, SEARCH/ESEARCH.
 */
final class CommandDispatcher
{
    private Event|null $lastEvent = null;

    public function __construct(
        private readonly StateMachine $stateMachine,
        private readonly EventEmitter $emitter,
        private readonly ResponseBuilder $responseBuilder,
        private readonly Session $session,
    ) {}

    /**
     * Dispatch a parsed command. Returns the wire-format response string(s).
     *
     * @return list<string>
     */
    public function dispatch(Command $command): array
    {
        $this->lastEvent = null;

        try {
            $this->stateMachine->assertCommandAllowed($command->name);
        } catch (StateException $e) {
            return [$this->responseBuilder->bad(
                $command->tag,
                $e->getMessage(),
            )];
        }

        if ($command instanceof LoginCommand && $this->session->isLoginDisabled()) {
            return [$this->responseBuilder->bad(
                $command->tag,
                'LOGIN disabled, use STARTTLS or AUTHENTICATE',
            )];
        }

        if ($command instanceof SimpleCommand
            && $command->name === 'STARTTLS'
            && $this->session->isTlsActive()
        ) {
            return [$this->responseBuilder->bad(
                $command->tag,
                'TLS already active',
            )];
        }

        if ($command instanceof EnableCommand) {
            return $this->handleEnable($command);
        }

        if ($this->isCondstoreEnabling($command)
            && (count($this->session->capabilities()) === 0 || $this->session->hasCapability('CONDSTORE'))
        ) {
            $this->session->enableCondstore();
        }

        $event = $this->createEvent($command);
        $this->lastEvent = $event;
        $this->emitter->emit($event);

        if (!$event->isHandled()) {

            if ($event instanceof AuthenticateEvent && $event->hasPendingChallenges()) {
                $responses = [];
                foreach ($event->drainChallenges() as $challenge) {
                    $responses[] = $this->responseBuilder->continuation($challenge);
                }
                return $responses;
            }

            return [$this->responseBuilder->no(
                $command->tag,
                $command->name . ' not implemented',
            )];
        }

        if ($event->isAccepted()) {
            $this->stateMachine->applySuccessTransition($command->name);
            return $this->buildAcceptResponse($command, $event);
        }

        if ($command instanceof SelectCommand && $this->stateMachine->state() === ConnectionState::Selected) {
            $this->stateMachine->deselected();
        }

        return [$this->responseBuilder->tagged(
            $command->tag,
            $event->getRejectStatus(),
            $event->getRejectText(),
            $event->getRejectCode(),
        )];
    }

    /**
     * Handle ENABLE at the protocol level.
     * Processes capabilities, updates session, then dispatches event.
     *
     * @return list<string>
     */
    private function handleEnable(EnableCommand $command): array
    {

        $requested = $command->capabilities;
        $advertised = $this->session->capabilities();
        if (count($advertised) > 0) {
            $requested = array_values(array_filter(
                $requested,
                static fn(string $capability): bool => $advertised->has($capability),
            ));
        }

        $enabled = $this->session->processEnable($requested);

        $event = new SimpleEvent($command);
        $this->emitter->emit($event);

        $responses = [];
        $responses[] = $this->responseBuilder->enabled($enabled);
        $responses[] = $this->responseBuilder->ok($command->tag, 'ENABLE completed');

        return $responses;
    }

    private function isCondstoreEnabling(Command $command): bool
    {
        if ($command instanceof SelectCommand) {
            return $command->condstore;
        }

        if ($command instanceof StoreCommand) {
            return $command->unchangedSince !== null;
        }

        if ($command instanceof \PHPdot\Mail\IMAP\Server\Command\StatusCommand) {
            return in_array(\PHPdot\Mail\IMAP\DataType\Enum\StatusAttribute::HighestModseq, $command->attributes, true);
        }

        if ($command instanceof FetchCommand) {
            if ($command->changedSince !== null) {
                return true;
            }
            foreach ($command->items as $item) {
                if (strtoupper($item->stringValue()) === 'MODSEQ') {
                    return true;
                }
            }

            return false;
        }

        if ($command instanceof SearchCommand) {
            return self::usesModseq($command->queries);
        }

        return false;
    }

    /**
     * @param array<array-key, mixed> $queries
     */
    public static function usesModseq(array $queries): bool
    {
        foreach ($queries as $query) {
            if (!$query instanceof \PHPdot\Mail\IMAP\DataType\DTO\SearchQuery) {
                continue;
            }
            if ($query->key === \PHPdot\Mail\IMAP\DataType\Enum\SearchKey::Modseq || self::usesModseq($query->children)) {
                return true;
            }
        }

        return false;
    }

    private function createEvent(Command $command): Event
    {
        return match (true) {
            $command instanceof LoginCommand => new LoginEvent($command),
            $command instanceof AuthenticateCommand => new AuthenticateEvent($command),
            $command instanceof SelectCommand => new SelectEvent($command),
            $command instanceof FetchCommand => new FetchEvent($command),
            $command instanceof SearchCommand => new SearchEvent($command),
            $command instanceof StoreCommand => new StoreEvent($command),
            $command instanceof AppendCommand => new AppendEvent($command),
            $command instanceof CopyMoveCommand => str_contains($command->name, 'MOVE')
                ? new MoveEvent($command)
                : new CopyEvent($command),
            $command instanceof ListCommand => new ListEvent($command),
            $command instanceof \PHPdot\Mail\IMAP\Server\Command\StatusCommand => new StatusEvent($command),
            $command instanceof MailboxCommand => new MailboxEvent($command),
            $command instanceof RenameCommand => new RenameEvent($command),
            $command instanceof UidExpungeCommand => new UidExpungeEvent($command),
            $command instanceof CompressCommand => new CompressEvent($command),
            $command instanceof IdCommand => new IdEvent($command),
            $command instanceof QuotaCommand => new QuotaEvent($command),
            $command instanceof AclCommand => new AclEvent($command),
            $command instanceof MetadataCommand => new MetadataEvent($command),
            $command instanceof SimpleCommand => $this->createSimpleEvent($command),
            default => new SimpleEvent($command),
        };
    }

    private function createSimpleEvent(SimpleCommand $command): Event
    {
        if ($command->name === 'IDLE') {
            return new IdleEvent($command);
        }
        return new SimpleEvent($command);
    }

    /**
     * @return list<string>
     */
    private function buildAcceptResponse(Command $command, Event $event): array
    {
        $responses = [];

        if ($command->name === 'LOGOUT') {
            $responses[] = $this->responseBuilder->bye();
        }

        if ($command->name === 'IDLE') {
            $responses[] = $this->responseBuilder->continuation('idling');
            return $responses;
        }

        if (($command instanceof LoginCommand || $command instanceof AuthenticateCommand)
            && $event->getResult() instanceof CapabilitySet) {
            $caps = $event->getResult();
            $this->session->setCapabilities($caps);
            $responses[] = $this->responseBuilder->capability($caps);
        }

        if ($command->name === 'CAPABILITY' && $event->getResult() instanceof CapabilitySet) {
            $caps = $event->getResult();
            $this->session->setCapabilities($caps);
            $responses[] = $this->responseBuilder->capability($caps);
        }

        if ($command instanceof AppendCommand) {
            $result = $event->getResult();
            if (is_array($result) && count($result) === 2) {
                /** @var array{0: int, 1: int} $result */
                $responses[] = $this->responseBuilder->okAppendUid(
                    $command->tag,
                    $result[0],
                    $result[1],
                    'APPEND completed',
                );
                return $responses;
            }
        }

        if ($command instanceof CopyMoveCommand) {
            $result = $event->getResult();
            $copyUid = is_array($result) ? ($result['copyUid'] ?? null) : null;
            if (is_array($copyUid) && count($copyUid) === 3
                && is_int($copyUid[0]) && is_array($copyUid[1]) && is_array($copyUid[2])
            ) {

                $toIntList = static function (array $values): array {
                    $ints = [];
                    foreach ($values as $v) {
                        if (is_int($v) || (is_string($v) && ctype_digit($v))) {
                            $ints[] = (int) $v;
                        }
                    }

                    return $ints;
                };
                $responses[] = $this->responseBuilder->okCopyUid(
                    $command->tag,
                    $copyUid[0],
                    SequenceSet::fromArray($toIntList($copyUid[1])),
                    SequenceSet::fromArray($toIntList($copyUid[2])),
                    $command->name . ' completed',
                );

                return $responses;
            }
        }

        if ($command instanceof StoreCommand) {
            $result = $event->getResult();
            $modified = is_array($result) ? ($result['modified'] ?? null) : null;
            if (is_array($modified) && $modified !== []) {
                $numbers = [];
                foreach ($modified as $number) {
                    if (is_int($number)) {
                        $numbers[] = $number;
                    }
                }
                $responses[] = $this->responseBuilder->okModified(
                    $command->tag,
                    SequenceSet::fromArray($numbers)->toWireString(),
                );

                return $responses;
            }
        }

        if ($command instanceof SelectCommand && $event->getResult() instanceof SelectResult) {
            $responses[] = $this->responseBuilder->ok(
                $command->tag,
                $command->name . ' completed',
                $event->getResult()->readWrite && !$command->readOnly ? ResponseCode::ReadWrite : ResponseCode::ReadOnly,
            );

            return $responses;
        }

        $responses[] = $this->responseBuilder->ok(
            $command->tag,
            $command->name . ' completed',
        );

        return $responses;
    }

    public function session(): Session
    {
        return $this->session;
    }

    public function stateMachine(): StateMachine
    {
        return $this->stateMachine;
    }

    public function lastEvent(): Event|null
    {
        return $this->lastEvent;
    }
}
