<?php
/**
 * Builds IMAP wire-format server responses.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

declare(strict_types=1);

namespace PHPdot\Mail\IMAP\Server\Response;

use PHPdot\Mail\IMAP\DataType\DTO\FetchResult;
use PHPdot\Mail\IMAP\DataType\DTO\MailboxInfo;
use PHPdot\Mail\IMAP\DataType\DTO\StatusInfo;
use PHPdot\Mail\IMAP\DataType\Enum\ResponseCode;
use PHPdot\Mail\IMAP\DataType\Enum\ResponseStatus;
use PHPdot\Mail\IMAP\DataType\ValueObject\CapabilitySet;
use PHPdot\Mail\IMAP\DataType\ValueObject\Flag;
use PHPdot\Mail\IMAP\DataType\ValueObject\NamespaceSet;
use PHPdot\Mail\IMAP\DataType\ValueObject\SequenceSet;
use PHPdot\Mail\IMAP\DataType\ValueObject\Tag;
use PHPdot\Mail\IMAP\Protocol\Compiler;
use PHPdot\Mail\IMAP\Protocol\WireFormat;

/**
 * Builds wire-format IMAP server responses.
 */
final class ResponseBuilder
{
    private readonly Compiler $compiler;

    public function __construct()
    {
        $this->compiler = new Compiler();
    }

    public function tagged(Tag $tag, ResponseStatus $status, string $text = '', ?ResponseCode $code = null): string
    {
        $response = $tag->value . ' ' . $status->value;
        if ($code !== null) {
            $response .= ' [' . $code->value . ']';
        }
        if ($text !== '') {
            $response .= ' ' . $text;
        }
        return $response . "\r\n";
    }

    public function ok(Tag $tag, string $text = '', ?ResponseCode $code = null): string
    {
        return $this->tagged($tag, ResponseStatus::Ok, $text, $code);
    }

    public function no(Tag $tag, string $text = '', ?ResponseCode $code = null): string
    {
        return $this->tagged($tag, ResponseStatus::No, $text, $code);
    }

    public function bad(Tag $tag, string $text = '', ?ResponseCode $code = null): string
    {
        return $this->tagged($tag, ResponseStatus::Bad, $text, $code);
    }

    public function untagged(string $content): string
    {
        return '* ' . $content . "\r\n";
    }

    public function untaggedOk(string $text, ?ResponseCode $code = null): string
    {
        if ($code !== null) {
            return '* OK [' . $code->value . '] ' . $text . "\r\n";
        }
        return '* OK ' . $text . "\r\n";
    }

    public function untaggedOkWithData(string $code, string $data, string $text): string
    {
        return '* OK [' . $code . ' ' . $data . '] ' . $text . "\r\n";
    }

    public function continuation(string $text = ''): string
    {
        return $this->compiler->compileContinuation($text);
    }

    public function greeting(string $text = 'IMAP4rev2 Server ready'): string
    {
        return '* OK ' . $text . "\r\n";
    }

    public function greetingWithCapability(CapabilitySet $capabilities, string $text = 'Server ready'): string
    {
        return '* OK [CAPABILITY ' . $capabilities->toWireString() . '] ' . $text . "\r\n";
    }

    public function bye(string $text = 'Server closing connection'): string
    {
        return '* BYE ' . $text . "\r\n";
    }

    public function exists(int $count): string
    {
        return '* ' . $count . " EXISTS\r\n";
    }

    public function recent(int $count): string
    {
        return '* ' . $count . " RECENT\r\n";
    }

    public function expunge(int $sequenceNumber): string
    {
        return '* ' . $sequenceNumber . " EXPUNGE\r\n";
    }

    /**
     * @param list<Flag> $flags
     */
    public function flags(array $flags): string
    {
        $flagStrs = array_map(static fn(Flag $f): string => $f->value, $flags);
        return '* FLAGS (' . implode(' ', $flagStrs) . ")\r\n";
    }

    public function capability(CapabilitySet $capabilities): string
    {
        return '* CAPABILITY ' . $capabilities->toWireString() . "\r\n";
    }

    public function list(MailboxInfo $info): string
    {
        $attrs = array_map(
            static fn($a) => $a->value,
            $info->attributes,
        );

        $delimiter = $info->delimiter !== null
            ? $this->compiler->compileQuoted($info->delimiter)
            : 'NIL';

        $name = $this->compiler->compileString($info->mailbox->name);

        return '* LIST (' . implode(' ', $attrs) . ') ' . $delimiter . ' ' . $name . "\r\n";
    }

    public function status(StatusInfo $statusInfo): string
    {
        $items = [];

        if ($statusInfo->messages !== null) {
            $items[] = 'MESSAGES ' . $statusInfo->messages;
        }
        if ($statusInfo->uidNext !== null) {
            $items[] = 'UIDNEXT ' . $statusInfo->uidNext;
        }
        if ($statusInfo->uidValidity !== null) {
            $items[] = 'UIDVALIDITY ' . $statusInfo->uidValidity;
        }
        if ($statusInfo->unseen !== null) {
            $items[] = 'UNSEEN ' . $statusInfo->unseen;
        }
        if ($statusInfo->deleted !== null) {
            $items[] = 'DELETED ' . $statusInfo->deleted;
        }
        if ($statusInfo->size !== null) {
            $items[] = 'SIZE ' . $statusInfo->size;
        }
        if ($statusInfo->highestModseq !== null) {
            $items[] = 'HIGHESTMODSEQ ' . $statusInfo->highestModseq;
        }
        if ($statusInfo->recent !== null) {
            $items[] = 'RECENT ' . $statusInfo->recent;
        }

        $name = $this->compiler->compileString($statusInfo->mailbox->name);
        return '* STATUS ' . $name . ' (' . implode(' ', $items) . ")\r\n";
    }

    public function namespace(NamespaceSet $ns): string
    {
        $parts = [];
        foreach ([$ns->personal, $ns->otherUsers, $ns->shared] as $group) {
            if ($group === null) {
                $parts[] = 'NIL';
            } else {
                $entries = [];
                foreach ($group as $entry) {
                    $entries[] = (string) $entry;
                }
                $parts[] = '(' . implode('', $entries) . ')';
            }
        }
        return '* NAMESPACE ' . implode(' ', $parts) . "\r\n";
    }

    /**
     * @param list<int> $results
     */
    public function search(array $results): string
    {
        if ($results === []) {
            return "* SEARCH\r\n";
        }
        return '* SEARCH ' . implode(' ', array_map('strval', $results)) . "\r\n";
    }

    /**
     * @param list<string> $enabled
     */
    public function enabled(array $enabled): string
    {
        if ($enabled === []) {
            return "* ENABLED\r\n";
        }
        return '* ENABLED ' . implode(' ', $enabled) . "\r\n";
    }

    /**
     * @param array<string, string> $params
     */
    public function id(array $params): string
    {
        if ($params === []) {
            return "* ID NIL\r\n";
        }
        $items = [];
        foreach ($params as $key => $value) {
            $items[] = $this->compiler->compileQuoted($key);
            $items[] = $this->compiler->compileQuoted($value);
        }
        return '* ID (' . implode(' ', $items) . ")\r\n";
    }

    public function quota(string $root, int $usageKB, int $limitKB): string
    {
        $rootStr = $this->compiler->compileString($root);
        return '* QUOTA ' . $rootStr . ' (STORAGE ' . $usageKB . ' ' . $limitKB . ")\r\n";
    }

    public function quotaRoot(string $mailbox, string $root): string
    {
        $mbStr = $this->compiler->compileString($mailbox);
        $rootStr = $this->compiler->compileString($root);
        return '* QUOTAROOT ' . $mbStr . ' ' . $rootStr . "\r\n";
    }

    /**
     * Build a FETCH response line for one message.
     */
    public function fetch(FetchResult $result): string
    {
        return WireFormat::fetchResponse($result);
    }

    /**
     * Build an ESEARCH response.
     *
     * @param list<string> $returnData Key-value pairs like ['ALL', '1:3', 'COUNT', '3']
     */
    public function esearch(?string $tag = null, bool $uid = false, array $returnData = []): string
    {
        $parts = ['* ESEARCH'];

        if ($tag !== null) {
            $parts[] = '(TAG "' . $tag . '")';
        }

        if ($uid) {
            $parts[] = 'UID';
        }

        for ($i = 0, $len = count($returnData); $i + 1 < $len; $i += 2) {
            $parts[] = $returnData[$i] . ' ' . $returnData[$i + 1];
        }

        return implode(' ', $parts) . "\r\n";
    }

    /**
     * Build a BINARY response section.
     */
    public function binary(int $sequenceNumber, string $section, string $data): string
    {
        return '* ' . $sequenceNumber . ' FETCH (BINARY[' . $section . '] ~{' . strlen($data) . "}\r\n" . $data . ")\r\n";
    }

    /**
     * Build an OK response with APPENDUID code.
     */
    public function okAppendUid(Tag $tag, int $uidValidity, int $uid, string $text = 'APPEND completed'): string
    {
        return $tag->value . ' OK [APPENDUID ' . $uidValidity . ' ' . $uid . '] ' . $text . "\r\n";
    }

    /**
     * Build an OK response with COPYUID code.
     */
    public function okCopyUid(Tag $tag, int $uidValidity, SequenceSet $sourceUids, SequenceSet $destUids, string $text = 'COPY completed'): string
    {
        return $tag->value . ' OK [COPYUID ' . $uidValidity . ' ' . $sourceUids->toWireString() . ' ' . $destUids->toWireString() . '] ' . $text . "\r\n";
    }

    /**
     * Build an OK response with UIDVALIDITY code.
     */
    public function okUidValidity(int $uidValidity): string
    {
        return '* OK [UIDVALIDITY ' . $uidValidity . "] UIDs valid\r\n";
    }

    /**
     * Build an OK response with UIDNEXT code.
     */
    public function okUidNext(int $uidNext): string
    {
        return '* OK [UIDNEXT ' . $uidNext . "] Predicted next UID\r\n";
    }

    /**
     * Build an OK response with HIGHESTMODSEQ code.
     */
    public function okHighestModseq(int $modseq): string
    {
        return '* OK [HIGHESTMODSEQ ' . $modseq . "] Highest\r\n";
    }

    /**
     * Build an OK response with PERMANENTFLAGS code.
     *
     * @param list<Flag> $flags
     */
    public function okPermanentFlags(array $flags, bool $allowCustom = true): string
    {
        $flagStrs = array_map(static fn(Flag $f): string => $f->value, $flags);
        if ($allowCustom) {
            $flagStrs[] = '\\*';
        }
        return '* OK [PERMANENTFLAGS (' . implode(' ', $flagStrs) . ")] Flags permitted\r\n";
    }

    // === CONDSTORE / QRESYNC ===

    /**
     * Build VANISHED response for QRESYNC.
     * Sent during SELECT QRESYNC or as unsolicited during IDLE.
     */
    public function vanished(string $uidSet, bool $earlier = false): string
    {
        if ($earlier) {
            return '* VANISHED (EARLIER) ' . $uidSet . "\r\n";
        }
        return '* VANISHED ' . $uidSet . "\r\n";
    }

    /**
     * Build OK response with MODIFIED code (CONDSTORE STORE failure).
     * When UNCHANGEDSINCE is used and some messages have been modified,
     * the server returns MODIFIED with the failed sequence set.
     */
    public function okModified(Tag $tag, string $modifiedSet, string $text = 'Conditional STORE failed'): string
    {
        return $tag->value . ' OK [MODIFIED ' . $modifiedSet . '] ' . $text . "\r\n";
    }

    /**
     * Build FETCH response with MODSEQ (CONDSTORE-aware).
     *
     * @param list<Flag> $flags
     */
    public function fetchWithModseq(int $seq, int $uid, array $flags, int $modseq): string
    {
        $flagStrs = array_map(static fn(Flag $f): string => $f->value, $flags);
        return '* ' . $seq . ' FETCH (UID ' . $uid . ' FLAGS (' . implode(' ', $flagStrs) . ') MODSEQ (' . $modseq . "))\r\n";
    }

    /**
     * Build SEARCH response with highest MODSEQ appended (CONDSTORE).
     *
     * @param list<int> $results
     */
    public function searchWithModseq(array $results, int $highestModseq): string
    {
        $line = '* SEARCH';
        if ($results !== []) {
            $line .= ' ' . implode(' ', array_map('strval', $results));
        }
        $line .= ' (MODSEQ ' . $highestModseq . ')';
        return $line . "\r\n";
    }
}
