<?php
/**
 * Builds IMAP wire-format client commands.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

declare(strict_types=1);

namespace PHPdot\Mail\IMAP\Client\Command;

use PHPdot\Mail\IMAP\Client\TagGenerator;
use PHPdot\Mail\IMAP\DataType\ValueObject\Flag;
use PHPdot\Mail\IMAP\DataType\ValueObject\Tag;
use PHPdot\Mail\IMAP\Protocol\Compiler;

/**
 * Builds wire-format IMAP client commands.
 *
 * Each method returns [Tag, string] — the tag for correlation and the complete wire bytes.
 *
 * @phpstan-type CommandResult array{0: Tag, 1: string}
 */
final class CommandBuilder
{
    private readonly TagGenerator $tagGenerator;
    private readonly Compiler $compiler;

    public function __construct(?TagGenerator $tagGenerator = null)
    {
        $this->tagGenerator = $tagGenerator ?? new TagGenerator();
        $this->compiler = new Compiler();
    }

    /**
     * @return array{0: Tag, 1: string}
     */
    public function capability(): array
    {
        return $this->simple('CAPABILITY');
    }

    /**
     * @return array{0: Tag, 1: string}
     */
    public function noop(): array
    {
        return $this->simple('NOOP');
    }

    /**
     * @return array{0: Tag, 1: string}
     */
    public function logout(): array
    {
        return $this->simple('LOGOUT');
    }

    /**
     * @return array{0: Tag, 1: string}
     */
    public function startTls(): array
    {
        return $this->simple('STARTTLS');
    }

    /**
     * @return array{0: Tag, 1: string}
     */
    public function login(string $userid, string $password): array
    {
        $tag = $this->tagGenerator->next();
        $line = $tag->value . ' LOGIN '
            . $this->compiler->compileString($userid) . ' '
            . $this->compiler->compileString($password) . "\r\n";
        return [$tag, $line];
    }

    /**
     * @return array{0: Tag, 1: string}
     */
    public function authenticate(string $mechanism, ?string $initialResponse = null): array
    {
        $tag = $this->tagGenerator->next();
        $line = $tag->value . ' AUTHENTICATE ' . strtoupper($mechanism);
        if ($initialResponse !== null) {
            $line .= ' ' . $initialResponse;
        }
        $line .= "\r\n";
        return [$tag, $line];
    }

    /**
     * @return array{0: Tag, 1: string}
     */
    public function select(string $mailbox): array
    {
        return $this->mailboxCommand('SELECT', $mailbox);
    }

    /**
     * SELECT with QRESYNC for fast resync (RFC 7162).
     *
     * @param string $knownUids Known UIDs for matching (optional)
     * @return array{0: Tag, 1: string}
     */
    public function selectQresync(string $mailbox, int $uidValidity, int $modseq, string $knownUids = ''): array
    {
        $tag = $this->tagGenerator->next();
        $qresync = '(QRESYNC (' . $uidValidity . ' ' . $modseq;
        if ($knownUids !== '') {
            $qresync .= ' ' . $knownUids;
        }
        $qresync .= '))';
        $line = $tag->value . ' SELECT ' . $this->compiler->compileString($mailbox) . ' ' . $qresync . "\r\n";
        return [$tag, $line];
    }

    /**
     * @return array{0: Tag, 1: string}
     */
    public function examine(string $mailbox): array
    {
        return $this->mailboxCommand('EXAMINE', $mailbox);
    }

    /**
     * @return array{0: Tag, 1: string}
     */
    public function create(string $mailbox): array
    {
        return $this->mailboxCommand('CREATE', $mailbox);
    }

    /**
     * @return array{0: Tag, 1: string}
     */
    public function delete(string $mailbox): array
    {
        return $this->mailboxCommand('DELETE', $mailbox);
    }

    /**
     * @return array{0: Tag, 1: string}
     */
    public function rename(string $from, string $to): array
    {
        $tag = $this->tagGenerator->next();
        $line = $tag->value . ' RENAME '
            . $this->compiler->compileString($from) . ' '
            . $this->compiler->compileString($to) . "\r\n";
        return [$tag, $line];
    }

    /**
     * @return array{0: Tag, 1: string}
     */
    public function subscribe(string $mailbox): array
    {
        return $this->mailboxCommand('SUBSCRIBE', $mailbox);
    }

    /**
     * @return array{0: Tag, 1: string}
     */
    public function unsubscribe(string $mailbox): array
    {
        return $this->mailboxCommand('UNSUBSCRIBE', $mailbox);
    }

    /**
     * @return array{0: Tag, 1: string}
     */
    public function list(string $reference, string $pattern): array
    {
        $tag = $this->tagGenerator->next();
        $line = $tag->value . ' LIST '
            . $this->compiler->compileString($reference) . ' '
            . $this->compiler->compileString($pattern) . "\r\n";
        return [$tag, $line];
    }

    /**
     * @param list<string> $attributes
     * @return array{0: Tag, 1: string}
     */
    public function status(string $mailbox, array $attributes): array
    {
        $tag = $this->tagGenerator->next();
        $line = $tag->value . ' STATUS '
            . $this->compiler->compileString($mailbox) . ' ('
            . implode(' ', $attributes) . ")\r\n";
        return [$tag, $line];
    }

    /**
     * @param list<Flag> $flags
     * @return array{0: Tag, 1: string}
     */
    public function append(string $mailbox, string $message, array $flags = [], ?string $internalDate = null): array
    {
        $tag = $this->tagGenerator->next();
        $line = $tag->value . ' APPEND ' . $this->compiler->compileString($mailbox);

        if ($flags !== []) {
            $flagStrs = array_map(static fn(Flag $f): string => $f->value, $flags);
            $line .= ' (' . implode(' ', $flagStrs) . ')';
        }

        if ($internalDate !== null) {
            $line .= ' ' . $this->compiler->compileQuoted($internalDate);
        }

        $line .= ' ' . $this->compiler->compileLiteral($message) . "\r\n";
        return [$tag, $line];
    }

    /**
     * @return array{0: Tag, 1: string}
     */
    public function close(): array
    {
        return $this->simple('CLOSE');
    }

    /**
     * @return array{0: Tag, 1: string}
     */
    public function unselect(): array
    {
        return $this->simple('UNSELECT');
    }

    /**
     * @return array{0: Tag, 1: string}
     */
    public function expunge(): array
    {
        return $this->simple('EXPUNGE');
    }

    /**
     * @return array{0: Tag, 1: string}
     */
    public function fetch(string $sequenceSet, string $items): array
    {
        $tag = $this->tagGenerator->next();
        $line = $tag->value . ' FETCH ' . $sequenceSet . ' ' . $items . "\r\n";
        return [$tag, $line];
    }

    /**
     * @return array{0: Tag, 1: string}
     */
    public function uidFetch(string $sequenceSet, string $items): array
    {
        $tag = $this->tagGenerator->next();
        $line = $tag->value . ' UID FETCH ' . $sequenceSet . ' ' . $items . "\r\n";
        return [$tag, $line];
    }

    /**
     * @return array{0: Tag, 1: string}
     */
    public function search(string $criteria): array
    {
        $tag = $this->tagGenerator->next();
        $line = $tag->value . ' SEARCH ' . $criteria . "\r\n";
        return [$tag, $line];
    }

    /**
     * @return array{0: Tag, 1: string}
     */
    public function uidSearch(string $criteria): array
    {
        $tag = $this->tagGenerator->next();
        $line = $tag->value . ' UID SEARCH ' . $criteria . "\r\n";
        return [$tag, $line];
    }

    /**
     * @return array{0: Tag, 1: string}
     */
    public function store(string $sequenceSet, string $action, string $flags): array
    {
        $tag = $this->tagGenerator->next();
        $line = $tag->value . ' STORE ' . $sequenceSet . ' ' . $action . ' ' . $flags . "\r\n";
        return [$tag, $line];
    }

    /**
     * @return array{0: Tag, 1: string}
     */
    public function copy(string $sequenceSet, string $mailbox): array
    {
        $tag = $this->tagGenerator->next();
        $line = $tag->value . ' COPY ' . $sequenceSet . ' ' . $this->compiler->compileString($mailbox) . "\r\n";
        return [$tag, $line];
    }

    /**
     * @return array{0: Tag, 1: string}
     */
    public function move(string $sequenceSet, string $mailbox): array
    {
        $tag = $this->tagGenerator->next();
        $line = $tag->value . ' MOVE ' . $sequenceSet . ' ' . $this->compiler->compileString($mailbox) . "\r\n";
        return [$tag, $line];
    }

    /**
     * @return array{0: Tag, 1: string}
     */
    public function idle(): array
    {
        return $this->simple('IDLE');
    }

    /**
     * Sends DONE to terminate IDLE (no tag — raw line).
     */
    public function done(): string
    {
        return "DONE\r\n";
    }

    /**
     * @return array{0: Tag, 1: string}
     */
    public function namespace(): array
    {
        return $this->simple('NAMESPACE');
    }

    /**
     * @param list<string> $capabilities
     * @return array{0: Tag, 1: string}
     */
    public function enable(array $capabilities): array
    {
        $tag = $this->tagGenerator->next();
        $line = $tag->value . ' ENABLE ' . implode(' ', $capabilities) . "\r\n";
        return [$tag, $line];
    }

    /**
     * @param array<string, string>|null $params
     * @return array{0: Tag, 1: string}
     */
    public function id(?array $params = null): array
    {
        $tag = $this->tagGenerator->next();
        if ($params === null) {
            $line = $tag->value . " ID NIL\r\n";
        } else {
            $items = [];
            foreach ($params as $key => $value) {
                $items[] = $this->compiler->compileQuoted($key);
                $items[] = $this->compiler->compileQuoted($value);
            }
            $line = $tag->value . ' ID (' . implode(' ', $items) . ")\r\n";
        }
        return [$tag, $line];
    }

    /**
     * @return array{0: Tag, 1: string}
     */
    public function compress(string $mechanism = 'DEFLATE'): array
    {
        $tag = $this->tagGenerator->next();
        $line = $tag->value . ' COMPRESS ' . strtoupper($mechanism) . "\r\n";
        return [$tag, $line];
    }

    /**
     * @return array{0: Tag, 1: string}
     */
    public function getQuota(string $root): array
    {
        $tag = $this->tagGenerator->next();
        $line = $tag->value . ' GETQUOTA ' . $this->compiler->compileString($root) . "\r\n";
        return [$tag, $line];
    }

    /**
     * @return array{0: Tag, 1: string}
     */
    public function getQuotaRoot(string $mailbox): array
    {
        $tag = $this->tagGenerator->next();
        $line = $tag->value . ' GETQUOTAROOT ' . $this->compiler->compileString($mailbox) . "\r\n";
        return [$tag, $line];
    }

    /**
     * @return array{0: Tag, 1: string}
     */
    public function uidExpunge(string $sequenceSet): array
    {
        $tag = $this->tagGenerator->next();
        $line = $tag->value . ' UID EXPUNGE ' . $sequenceSet . "\r\n";
        return [$tag, $line];
    }

    /**
     * @return array{0: Tag, 1: string}
     */
    public function uidStore(string $sequenceSet, string $action, string $flags): array
    {
        $tag = $this->tagGenerator->next();
        $line = $tag->value . ' UID STORE ' . $sequenceSet . ' ' . $action . ' ' . $flags . "\r\n";
        return [$tag, $line];
    }

    /**
     * @return array{0: Tag, 1: string}
     */
    public function uidCopy(string $sequenceSet, string $mailbox): array
    {
        $tag = $this->tagGenerator->next();
        $line = $tag->value . ' UID COPY ' . $sequenceSet . ' ' . $this->compiler->compileString($mailbox) . "\r\n";
        return [$tag, $line];
    }

    /**
     * @return array{0: Tag, 1: string}
     */
    public function uidMove(string $sequenceSet, string $mailbox): array
    {
        $tag = $this->tagGenerator->next();
        $line = $tag->value . ' UID MOVE ' . $sequenceSet . ' ' . $this->compiler->compileString($mailbox) . "\r\n";
        return [$tag, $line];
    }

    // === SORT & THREAD (RFC 5256) ===

    /**
     * @return array{0: Tag, 1: string}
     */
    public function sort(string $criteria, string $charset, string $searchCriteria): array
    {
        $tag = $this->tagGenerator->next();
        $line = $tag->value . ' SORT ' . $criteria . ' ' . $charset . ' ' . $searchCriteria . "\r\n";
        return [$tag, $line];
    }

    /**
     * @return array{0: Tag, 1: string}
     */
    public function uidSort(string $criteria, string $charset, string $searchCriteria): array
    {
        $tag = $this->tagGenerator->next();
        $line = $tag->value . ' UID SORT ' . $criteria . ' ' . $charset . ' ' . $searchCriteria . "\r\n";
        return [$tag, $line];
    }

    /**
     * @return array{0: Tag, 1: string}
     */
    public function thread(string $algorithm, string $charset, string $searchCriteria): array
    {
        $tag = $this->tagGenerator->next();
        $line = $tag->value . ' THREAD ' . $algorithm . ' ' . $charset . ' ' . $searchCriteria . "\r\n";
        return [$tag, $line];
    }

    /**
     * @return array{0: Tag, 1: string}
     */
    public function uidThread(string $algorithm, string $charset, string $searchCriteria): array
    {
        $tag = $this->tagGenerator->next();
        $line = $tag->value . ' UID THREAD ' . $algorithm . ' ' . $charset . ' ' . $searchCriteria . "\r\n";
        return [$tag, $line];
    }

    // === LSUB & LIST-EXTENDED (RFC 5258, RFC 5819) ===

    /**
     * @return array{0: Tag, 1: string}
     */
    public function lsub(string $reference, string $pattern): array
    {
        $tag = $this->tagGenerator->next();
        $line = $tag->value . ' LSUB '
            . $this->compiler->compileString($reference) . ' '
            . $this->compiler->compileString($pattern) . "\r\n";
        return [$tag, $line];
    }

    /**
     * @param list<string> $selectOptions
     * @param list<string> $returnOptions
     * @return array{0: Tag, 1: string}
     */
    public function listExtended(string $reference, string $pattern, array $selectOptions = [], array $returnOptions = []): array
    {
        $tag = $this->tagGenerator->next();
        $line = $tag->value . ' LIST';

        if ($selectOptions !== []) {
            $line .= ' (' . implode(' ', $selectOptions) . ')';
        }

        $line .= ' ' . $this->compiler->compileString($reference)
            . ' ' . $this->compiler->compileString($pattern);

        if ($returnOptions !== []) {
            $line .= ' RETURN (' . implode(' ', $returnOptions) . ')';
        }

        $line .= "\r\n";
        return [$tag, $line];
    }

    /**
     * @param list<string> $statusItems
     * @return array{0: Tag, 1: string}
     */
    public function listStatus(string $reference, string $pattern, array $statusItems): array
    {
        $tag = $this->tagGenerator->next();
        $line = $tag->value . ' LIST '
            . $this->compiler->compileString($reference) . ' '
            . $this->compiler->compileString($pattern)
            . ' RETURN (STATUS (' . implode(' ', $statusItems) . '))' . "\r\n";
        return [$tag, $line];
    }

    // === ACL (RFC 4314) ===

    /**
     * @return array{0: Tag, 1: string}
     */
    public function getAcl(string $mailbox): array
    {
        $tag = $this->tagGenerator->next();
        $line = $tag->value . ' GETACL ' . $this->compiler->compileString($mailbox) . "\r\n";
        return [$tag, $line];
    }

    /**
     * @return array{0: Tag, 1: string}
     */
    public function setAcl(string $mailbox, string $identifier, string $rights): array
    {
        $tag = $this->tagGenerator->next();
        $line = $tag->value . ' SETACL '
            . $this->compiler->compileString($mailbox) . ' '
            . $this->compiler->compileString($identifier) . ' '
            . $rights . "\r\n";
        return [$tag, $line];
    }

    /**
     * @return array{0: Tag, 1: string}
     */
    public function deleteAcl(string $mailbox, string $identifier): array
    {
        $tag = $this->tagGenerator->next();
        $line = $tag->value . ' DELETEACL '
            . $this->compiler->compileString($mailbox) . ' '
            . $this->compiler->compileString($identifier) . "\r\n";
        return [$tag, $line];
    }

    /**
     * @return array{0: Tag, 1: string}
     */
    public function myRights(string $mailbox): array
    {
        $tag = $this->tagGenerator->next();
        $line = $tag->value . ' MYRIGHTS ' . $this->compiler->compileString($mailbox) . "\r\n";
        return [$tag, $line];
    }

    /**
     * @return array{0: Tag, 1: string}
     */
    public function listRights(string $mailbox, string $identifier): array
    {
        $tag = $this->tagGenerator->next();
        $line = $tag->value . ' LISTRIGHTS '
            . $this->compiler->compileString($mailbox) . ' '
            . $this->compiler->compileString($identifier) . "\r\n";
        return [$tag, $line];
    }

    // === METADATA (RFC 5464) ===

    /**
     * @param list<string> $entries
     * @return array{0: Tag, 1: string}
     */
    public function getMetadata(string $mailbox, array $entries): array
    {
        $tag = $this->tagGenerator->next();
        $compiled = array_map(fn(string $e): string => $this->compiler->compileString($e), $entries);
        $line = $tag->value . ' GETMETADATA '
            . $this->compiler->compileString($mailbox) . ' ('
            . implode(' ', $compiled) . ")\r\n";
        return [$tag, $line];
    }

    /**
     * @param array<string, string|null> $entryValues entry => value (null to delete)
     * @return array{0: Tag, 1: string}
     */
    public function setMetadata(string $mailbox, array $entryValues): array
    {
        $tag = $this->tagGenerator->next();
        $pairs = [];
        foreach ($entryValues as $entry => $value) {
            $pairs[] = $this->compiler->compileString($entry);
            $pairs[] = $value === null ? 'NIL' : $this->compiler->compileString($value);
        }
        $line = $tag->value . ' SETMETADATA '
            . $this->compiler->compileString($mailbox) . ' ('
            . implode(' ', $pairs) . ")\r\n";
        return [$tag, $line];
    }

    // === QUOTA (RFC 2087) ===

    /**
     * @return array{0: Tag, 1: string}
     */
    public function setQuota(string $root, int $storageLimit): array
    {
        $tag = $this->tagGenerator->next();
        $line = $tag->value . ' SETQUOTA ' . $this->compiler->compileString($root)
            . ' (STORAGE ' . $storageLimit . ")\r\n";
        return [$tag, $line];
    }

    /**
     * @return array{0: Tag, 1: string}
     */
    private function simple(string $command): array
    {
        $tag = $this->tagGenerator->next();
        return [$tag, $tag->value . ' ' . $command . "\r\n"];
    }

    /**
     * @return array{0: Tag, 1: string}
     */
    private function mailboxCommand(string $command, string $mailbox): array
    {
        $tag = $this->tagGenerator->next();
        $line = $tag->value . ' ' . $command . ' ' . $this->compiler->compileString($mailbox) . "\r\n";
        return [$tag, $line];
    }
}
