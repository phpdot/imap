<?php

declare(strict_types=1);

/**
 * Maps IMAP command names to their allowed connection state groups.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Mail\IMAP\Protocol\StateMachine;

use PHPdot\Mail\IMAP\DataType\Enum\CommandGroup;

/**
 * Maps IMAP command names to their allowed connection state groups.
 */
final class CommandRegistry
{
    /** @var array<string, CommandGroup> */
    private static array $commands = [

        'CAPABILITY' => CommandGroup::Any,
        'NOOP' => CommandGroup::Any,
        'LOGOUT' => CommandGroup::Any,

        'STARTTLS' => CommandGroup::NonAuth,
        'LOGIN' => CommandGroup::NonAuth,
        'AUTHENTICATE' => CommandGroup::NonAuth,
        'AUTHENTICATE PLAIN' => CommandGroup::NonAuth,
        'AUTHENTICATE PLAIN-CLIENTTOKEN' => CommandGroup::NonAuth,
        'AUTHENTICATE XOAUTH2' => CommandGroup::NonAuth,
        'AUTHENTICATE OAUTHBEARER' => CommandGroup::NonAuth,

        'ENABLE' => CommandGroup::Auth,
        'SELECT' => CommandGroup::Auth,
        'EXAMINE' => CommandGroup::Auth,
        'CREATE' => CommandGroup::Auth,
        'DELETE' => CommandGroup::Auth,
        'RENAME' => CommandGroup::Auth,
        'SUBSCRIBE' => CommandGroup::Auth,
        'UNSUBSCRIBE' => CommandGroup::Auth,
        'LIST' => CommandGroup::Auth,
        'XLIST' => CommandGroup::Auth,
        'LSUB' => CommandGroup::Auth,
        'NAMESPACE' => CommandGroup::Auth,
        'STATUS' => CommandGroup::Auth,
        'APPEND' => CommandGroup::Auth,
        'IDLE' => CommandGroup::Auth,
        'ID' => CommandGroup::Any,
        'GETQUOTA' => CommandGroup::Auth,
        'GETQUOTAROOT' => CommandGroup::Auth,
        'SETQUOTA' => CommandGroup::Auth,

        'GETACL' => CommandGroup::Auth,
        'SETACL' => CommandGroup::Auth,
        'DELETEACL' => CommandGroup::Auth,
        'LISTRIGHTS' => CommandGroup::Auth,
        'MYRIGHTS' => CommandGroup::Auth,

        'GETMETADATA' => CommandGroup::Auth,
        'SETMETADATA' => CommandGroup::Auth,

        'CHECK' => CommandGroup::Selected,
        'CLOSE' => CommandGroup::Selected,
        'UNSELECT' => CommandGroup::Selected,
        'EXPUNGE' => CommandGroup::Selected,
        'SEARCH' => CommandGroup::Selected,
        'FETCH' => CommandGroup::Selected,
        'STORE' => CommandGroup::Selected,
        'COPY' => CommandGroup::Selected,
        'MOVE' => CommandGroup::Selected,
        'UID SEARCH' => CommandGroup::Selected,
        'UID FETCH' => CommandGroup::Selected,
        'UID STORE' => CommandGroup::Selected,
        'UID COPY' => CommandGroup::Selected,
        'UID MOVE' => CommandGroup::Selected,
        'UID EXPUNGE' => CommandGroup::Selected,
        'COMPRESS' => CommandGroup::Auth,
        'XAPPLEPUSHSERVICE' => CommandGroup::Auth,
    ];

    /** @var array<string, CommandGroup> */
    private array $custom = [];

    public function getGroup(string $command): CommandGroup|null
    {
        $upper = strtoupper(trim($command));
        return $this->custom[$upper] ?? self::$commands[$upper] ?? null;
    }

    public function register(string $command, CommandGroup $group): void
    {
        $this->custom[strtoupper(trim($command))] = $group;
    }

    public function isKnown(string $command): bool
    {
        $upper = strtoupper(trim($command));
        return isset($this->custom[$upper]) || isset(self::$commands[$upper]);
    }

    /**
     * @return list<string>
     */
    public function allCommands(): array
    {
        return array_keys(array_merge(self::$commands, $this->custom));
    }
}
