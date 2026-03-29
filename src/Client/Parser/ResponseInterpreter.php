<?php
/**
 * Interprets raw tokenized IMAP responses into typed DTOs.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

declare(strict_types=1);

namespace PHPdot\Mail\IMAP\Client\Parser;

use PHPdot\Mail\IMAP\DataType\DTO\Address;
use PHPdot\Mail\IMAP\DataType\DTO\BodyDisposition;
use PHPdot\Mail\IMAP\DataType\DTO\BodyFieldParams;
use PHPdot\Mail\IMAP\DataType\DTO\BodyStructure;
use PHPdot\Mail\IMAP\DataType\DTO\BodyStructureMultipart;
use PHPdot\Mail\IMAP\DataType\DTO\BodyStructurePart;
use PHPdot\Mail\IMAP\DataType\DTO\Envelope;
use PHPdot\Mail\IMAP\DataType\DTO\FetchResult;
use PHPdot\Mail\IMAP\DataType\DTO\MailboxInfo;
use PHPdot\Mail\IMAP\DataType\DTO\StatusInfo;
use PHPdot\Mail\IMAP\DataType\DTO\Token;
use PHPdot\Mail\IMAP\DataType\Enum\ContentEncoding;
use PHPdot\Mail\IMAP\DataType\Enum\MailboxAttribute;
use PHPdot\Mail\IMAP\DataType\Enum\TokenType;
use PHPdot\Mail\IMAP\DataType\ValueObject\Capability;
use PHPdot\Mail\IMAP\DataType\ValueObject\CapabilitySet;
use PHPdot\Mail\IMAP\DataType\ValueObject\Flag;
use PHPdot\Mail\IMAP\DataType\ValueObject\ImapDateTime;
use PHPdot\Mail\IMAP\DataType\ValueObject\Mailbox;
use PHPdot\Mail\IMAP\DataType\ValueObject\Namespace_;
use PHPdot\Mail\IMAP\DataType\ValueObject\NamespaceSet;
use PHPdot\Mail\IMAP\Client\Response\DataResponse;

/**
 * Interprets raw tokenized server responses into typed DTOs.
 *
 * Usage:
 *   $interpreter = new ResponseInterpreter();
 *   $fetchResult = $interpreter->interpretFetch($dataResponse);
 *   $mailboxInfo = $interpreter->interpretList($dataResponse);
 */
final class ResponseInterpreter
{
    /**
     * Interpret a FETCH response into a FetchResult DTO.
     *
     * Input: DataResponse where type=FETCH, number=sequence, tokens=msg-att contents
     */
    public function interpretFetch(DataResponse $response): FetchResult
    {
        $seq = $response->number ?? 0;
        $tokens = $response->tokens;

        // FETCH tokens come as the contents of the parenthesized list: FLAGS (\Seen) UID 101 ...
        // If wrapped in a list, unwrap
        if (count($tokens) === 1 && $tokens[0]->isList()) {
            $tokens = $tokens[0]->children;
        }

        $uid = null;
        $flags = null;
        $envelope = null;
        $bodyStructure = null;
        $internalDate = null;
        $rfc822Size = null;
        $modseq = null;
        /** @var array<string, string|null> $bodySections */
        $bodySections = [];

        for ($i = 0, $len = count($tokens); $i < $len; $i++) {
            $name = strtoupper($tokens[$i]->stringValue());

            match ($name) {
                'UID' => $uid = $tokens[++$i]->intValue(),
                'FLAGS' => $flags = $this->parseFlags($tokens[++$i]),
                'ENVELOPE' => $envelope = $this->parseEnvelope($tokens[++$i]),
                'BODYSTRUCTURE', 'BODY' => (function () use (&$i, $tokens, $name, &$bodyStructure, &$bodySections): void {
                    $next = $tokens[$i + 1] ?? null;
                    if ($next === null) {
                        return;
                    }
                    // BODY[section] or BODYSTRUCTURE
                    if ($name === 'BODY' && $next->isSection()) {
                        $section = $next->stringValue();
                        $i += 2; // skip section token
                        // Check for <origin> partial
                        if (isset($tokens[$i]) && $tokens[$i]->type === TokenType::Partial) {
                            $i++; // skip partial
                        }
                        $bodySections[$section] = isset($tokens[$i]) && !$tokens[$i]->isNil()
                            ? $tokens[$i]->stringValue()
                            : null;
                    } else {
                        $i++;
                        if ($next->isList()) {
                            $bodyStructure = $this->parseBodyStructure($next);
                        }
                    }
                })(),
                'INTERNALDATE' => $internalDate = $this->parseDateSafe($tokens[++$i]->stringValue()),
                'RFC822.SIZE' => $rfc822Size = $tokens[++$i]->intValue(),
                'BINARY' => (function () use (&$i, $tokens, &$bodySections): void {
                    $next = $tokens[$i + 1] ?? null;
                    if ($next === null) {
                        return;
                    }
                    if ($next->isSection()) {
                        $section = 'BINARY[' . $next->stringValue() . ']';
                        $i += 2;
                        $bodySections[$section] = isset($tokens[$i]) && !$tokens[$i]->isNil()
                            ? $tokens[$i]->stringValue()
                            : null;
                    } else {
                        $i++;
                    }
                })(),
                'MODSEQ' => (function () use (&$i, $tokens, &$modseq): void {
                    $i++;
                    $next = $tokens[$i];
                    if ($next->isList() && $next->children !== []) {
                        $modseq = $next->children[0]->intValue();
                    } else {
                        $modseq = $next->intValue();
                    }
                })(),
                default => null,
            };
        }

        return new FetchResult(
            sequenceNumber: $seq,
            uid: $uid,
            envelope: $envelope,
            bodyStructure: $bodyStructure,
            flags: $flags,
            internalDate: $internalDate,
            rfc822Size: $rfc822Size,
            bodySections: $bodySections,
            modseq: $modseq,
        );
    }

    /**
     * Interpret a LIST/LSUB response into a MailboxInfo DTO.
     *
     * Input: DataResponse where type=LIST, tokens = [(attrs) delimiter mailbox]
     */
    public function interpretList(DataResponse $response): MailboxInfo
    {
        $tokens = $response->tokens;

        // LIST response: (attrs) delimiter mailbox [extended]
        $attributes = [];
        $delimiter = null;
        $mailboxName = '';

        $pos = 0;

        // Attributes list
        if (isset($tokens[$pos]) && $tokens[$pos]->isList()) {
            foreach ($tokens[$pos]->children as $attrToken) {
                $attr = MailboxAttribute::tryFromCaseInsensitive($attrToken->stringValue());
                if ($attr !== null) {
                    $attributes[] = $attr;
                }
            }
            $pos++;
        }

        // Delimiter
        if (isset($tokens[$pos])) {
            $delimiter = $tokens[$pos]->isNil() ? null : $tokens[$pos]->stringValue();
            $pos++;
        }

        // Mailbox name
        if (isset($tokens[$pos])) {
            $mailboxName = $tokens[$pos]->stringValue();
        }

        return new MailboxInfo(
            mailbox: new Mailbox($mailboxName),
            delimiter: $delimiter,
            attributes: $attributes,
        );
    }

    /**
     * Interpret a STATUS response into a StatusInfo DTO.
     *
     * Input: DataResponse where type=STATUS, tokens = [mailbox (items)]
     */
    public function interpretStatus(DataResponse $response): StatusInfo
    {
        $tokens = $response->tokens;
        $mailboxName = isset($tokens[0]) ? $tokens[0]->stringValue() : '';
        $messages = null;
        $uidNext = null;
        $uidValidity = null;
        $unseen = null;
        $deleted = null;
        $size = null;
        $highestModseq = null;
        $recent = null;

        if (isset($tokens[1]) && $tokens[1]->isList()) {
            $items = $tokens[1]->children;
            for ($i = 0, $len = count($items); $i + 1 < $len; $i += 2) {
                $key = strtoupper($items[$i]->stringValue());
                $val = $items[$i + 1]->intValue();
                match ($key) {
                    'MESSAGES' => $messages = $val,
                    'UIDNEXT' => $uidNext = $val,
                    'UIDVALIDITY' => $uidValidity = $val,
                    'UNSEEN' => $unseen = $val,
                    'DELETED' => $deleted = $val,
                    'SIZE' => $size = $val,
                    'HIGHESTMODSEQ' => $highestModseq = $val,
                    'RECENT' => $recent = $val,
                    default => null,
                };
            }
        }

        return new StatusInfo(
            mailbox: new Mailbox($mailboxName),
            messages: $messages,
            uidNext: $uidNext,
            uidValidity: $uidValidity,
            unseen: $unseen,
            deleted: $deleted,
            size: $size,
            highestModseq: $highestModseq,
            recent: $recent,
        );
    }

    /**
     * Interpret a CAPABILITY response into a CapabilitySet.
     *
     * Input: DataResponse where type=CAPABILITY, tokens = [cap1 cap2 ...]
     */
    public function interpretCapability(DataResponse $response): CapabilitySet
    {
        $caps = [];
        foreach ($response->tokens as $token) {
            $caps[] = new Capability($token->stringValue());
        }
        return new CapabilitySet($caps);
    }

    /**
     * Interpret a FLAGS response into a list of Flag value objects.
     *
     * Input: DataResponse where type=FLAGS, tokens = [(flag1 flag2 ...)]
     *
     * @return list<Flag>
     */
    public function interpretFlags(DataResponse $response): array
    {
        if (isset($response->tokens[0]) && $response->tokens[0]->isList()) {
            return $this->parseFlags($response->tokens[0]);
        }
        return [];
    }

    /**
     * Interpret a SEARCH response into a list of numbers.
     *
     * @return list<int>
     */
    public function interpretSearch(DataResponse $response): array
    {
        $results = [];
        foreach ($response->tokens as $token) {
            if ($token->type === TokenType::Number) {
                $results[] = $token->intValue();
            }
        }
        return $results;
    }

    /**
     * Interpret an ESEARCH response.
     *
     * Parses: * ESEARCH (TAG "A1") UID MIN 1 MAX 500 COUNT 23 ALL 1:500
     *
     * @return array{tag: ?string, uid: bool, min: ?int, max: ?int, count: ?int, all: ?string}
     */
    public function interpretEsearch(DataResponse $response): array
    {
        $result = ['tag' => null, 'uid' => false, 'min' => null, 'max' => null, 'count' => null, 'all' => null];
        $tokens = $response->tokens;

        for ($i = 0, $len = count($tokens); $i < $len; $i++) {
            $val = strtoupper($tokens[$i]->stringValue());

            // (TAG "A1") — parenthesized tag
            if ($tokens[$i]->isList() && count($tokens[$i]->children) >= 2) {
                $tagKey = strtoupper($tokens[$i]->children[0]->stringValue());
                if ($tagKey === 'TAG') {
                    $result['tag'] = $tokens[$i]->children[1]->stringValue();
                }
                continue;
            }

            match ($val) {
                'UID' => $result['uid'] = true,
                'MIN' => $result['min'] = isset($tokens[$i + 1]) ? $tokens[++$i]->intValue() : null,
                'MAX' => $result['max'] = isset($tokens[$i + 1]) ? $tokens[++$i]->intValue() : null,
                'COUNT' => $result['count'] = isset($tokens[$i + 1]) ? $tokens[++$i]->intValue() : null,
                'ALL' => $result['all'] = isset($tokens[$i + 1]) ? $tokens[++$i]->stringValue() : null,
                default => null,
            };
        }

        return $result;
    }

    /**
     * Interpret a NAMESPACE response into a NamespaceSet.
     *
     * Input: DataResponse where type=NAMESPACE, tokens = [personal other shared]
     */
    public function interpretNamespace(DataResponse $response): NamespaceSet
    {
        $tokens = $response->tokens;
        return new NamespaceSet(
            personal: $this->parseNamespaceGroup($tokens[0] ?? null),
            otherUsers: $this->parseNamespaceGroup($tokens[1] ?? null),
            shared: $this->parseNamespaceGroup($tokens[2] ?? null),
        );
    }

    // === PRIVATE PARSERS ===

    /**
     * @return list<Flag>
     */
    private function parseFlags(Token $token): array
    {
        $flags = [];
        $children = $token->isList() ? $token->children : [$token];
        foreach ($children as $child) {
            if (!$child->isNil()) {
                $flags[] = new Flag($child->stringValue());
            }
        }
        return $flags;
    }

    /**
     * Parse ENVELOPE from a parenthesized list token.
     *
     * ENVELOPE = (date subject from sender reply-to to cc bcc in-reply-to message-id)
     */
    private function parseEnvelope(Token $token): Envelope
    {
        $children = $token->isList() ? $token->children : [];

        return new Envelope(
            date: $this->nstring($children[0] ?? null),
            subject: $this->nstring($children[1] ?? null),
            from: $this->parseAddressList($children[2] ?? null),
            sender: $this->parseAddressList($children[3] ?? null),
            replyTo: $this->parseAddressList($children[4] ?? null),
            to: $this->parseAddressList($children[5] ?? null),
            cc: $this->parseAddressList($children[6] ?? null),
            bcc: $this->parseAddressList($children[7] ?? null),
            inReplyTo: $this->nstring($children[8] ?? null),
            messageId: $this->nstring($children[9] ?? null),
        );
    }

    /**
     * @return list<Address>|null
     */
    private function parseAddressList(?Token $token): ?array
    {
        if ($token === null || $token->isNil()) {
            return null;
        }
        if (!$token->isList()) {
            return null;
        }

        $addresses = [];
        foreach ($token->children as $addrToken) {
            if ($addrToken->isList() && count($addrToken->children) === 4) {
                $c = $addrToken->children;
                $addresses[] = new Address(
                    name: $this->nstring($c[0]),
                    adl: $this->nstring($c[1]),
                    mailbox: $this->nstring($c[2]),
                    host: $this->nstring($c[3]),
                );
            }
        }
        return $addresses === [] ? null : $addresses;
    }

    /**
     * Parse BODYSTRUCTURE from a parenthesized list token.
     */
    private function parseBodyStructure(Token $token): BodyStructure
    {
        if (!$token->isList() || $token->children === []) {
            return new BodyStructure(new BodyStructurePart('TEXT', 'PLAIN'));
        }

        $children = $token->children;

        // Multipart: first child is also a list (nested body)
        if ($children[0]->isList()) {
            return $this->parseMultipartBody($children);
        }

        return new BodyStructure($this->parseSinglePartBody($children));
    }

    /**
     * @param list<Token> $children
     */
    private function parseSinglePartBody(array $children): BodyStructurePart
    {
        $type = strtoupper($this->nstring($children[0] ?? null) ?? 'TEXT');
        $subtype = strtoupper($this->nstring($children[1] ?? null) ?? 'PLAIN');
        $params = $this->parseBodyFieldParams($children[2] ?? null);
        $id = $this->nstring($children[3] ?? null);
        $description = $this->nstring($children[4] ?? null);
        $encoding = ContentEncoding::tryFromCaseInsensitive(
            $this->nstring($children[5] ?? null) ?? '7BIT'
        ) ?? ContentEncoding::SevenBit;
        $size = ($children[6] ?? null)?->intValue() ?? 0;

        $pos = 7;
        $lines = null;
        $envelope = null;
        $bodyStructure = null;

        // TEXT type: lines at pos 7
        if (strcasecmp($type, 'TEXT') === 0) {
            $lines = ($children[$pos] ?? null)?->intValue();
            $pos++;
        }

        // MESSAGE/RFC822: envelope, body, lines at pos 7,8,9
        if (strcasecmp($type, 'MESSAGE') === 0
            && (strcasecmp($subtype, 'RFC822') === 0 || strcasecmp($subtype, 'GLOBAL') === 0)) {
            if (isset($children[$pos]) && $children[$pos]->isList()) {
                $envelope = $this->parseEnvelope($children[$pos]);
                $pos++;
            }
            if (isset($children[$pos]) && $children[$pos]->isList()) {
                $bodyStructure = $this->parseBodyStructure($children[$pos]);
                $pos++;
            }
            $lines = ($children[$pos] ?? null)?->intValue();
            $pos++;
        }

        // Extension fields: md5, disposition, language, location
        $md5 = $this->nstring($children[$pos] ?? null);
        $pos++;
        $disposition = $this->parseBodyDisposition($children[$pos] ?? null);
        $pos++;
        $language = $this->parseBodyLanguage($children[$pos] ?? null);
        $pos++;
        $location = $this->nstring($children[$pos] ?? null);

        return new BodyStructurePart(
            type: $type,
            subtype: $subtype,
            params: $params,
            id: $id,
            description: $description,
            encoding: $encoding,
            size: $size,
            lines: $lines,
            md5: $md5,
            disposition: $disposition,
            language: $language,
            location: $location,
            envelope: $envelope,
            bodyStructure: $bodyStructure,
        );
    }

    /**
     * @param list<Token> $children
     */
    private function parseMultipartBody(array $children): BodyStructure
    {
        $parts = [];
        $pos = 0;

        // Collect nested body parts (each is a list)
        while ($pos < count($children) && $children[$pos]->isList()) {
            $parts[] = $this->parseBodyStructure($children[$pos]);
            $pos++;
        }

        // media-subtype
        $subtype = strtoupper($this->nstring($children[$pos] ?? null) ?? 'MIXED');
        $pos++;

        // Extension fields: params, disposition, language, location
        $params = $this->parseBodyFieldParams($children[$pos] ?? null);
        $pos++;
        $disposition = $this->parseBodyDisposition($children[$pos] ?? null);
        $pos++;
        $language = $this->parseBodyLanguage($children[$pos] ?? null);
        $pos++;
        $location = $this->nstring($children[$pos] ?? null);

        return new BodyStructure(new BodyStructureMultipart(
            parts: $parts,
            subtype: $subtype,
            params: $params,
            disposition: $disposition,
            language: $language,
            location: $location,
        ));
    }

    private function parseBodyFieldParams(?Token $token): ?BodyFieldParams
    {
        if ($token === null || $token->isNil() || !$token->isList()) {
            return null;
        }
        $params = [];
        $children = $token->children;
        for ($i = 0, $len = count($children); $i + 1 < $len; $i += 2) {
            $params[$children[$i]->stringValue()] = $children[$i + 1]->stringValue();
        }
        return $params !== [] ? new BodyFieldParams($params) : null;
    }

    private function parseBodyDisposition(?Token $token): ?BodyDisposition
    {
        if ($token === null || $token->isNil() || !$token->isList()) {
            return null;
        }
        $children = $token->children;
        $type = ($children[0] ?? null)?->stringValue() ?? 'ATTACHMENT';
        $params = $this->parseBodyFieldParams($children[1] ?? null);
        return new BodyDisposition($type, $params);
    }

    /**
     * @return list<string>|string|null
     */
    private function parseBodyLanguage(?Token $token): array|string|null
    {
        if ($token === null || $token->isNil()) {
            return null;
        }
        if ($token->isList()) {
            return array_map(
                static fn(Token $t): string => $t->stringValue(),
                $token->children,
            );
        }
        return $token->stringValue();
    }

    /**
     * @return list<Namespace_>|null
     */
    private function parseNamespaceGroup(?Token $token): ?array
    {
        if ($token === null || $token->isNil()) {
            return null;
        }
        if (!$token->isList()) {
            return null;
        }
        $result = [];
        foreach ($token->children as $child) {
            if ($child->isList() && count($child->children) >= 2) {
                $prefix = $child->children[0]->stringValue();
                $delimiter = $child->children[1]->isNil() ? null : $child->children[1]->stringValue();
                $result[] = new Namespace_($prefix, $delimiter);
            }
        }
        return $result === [] ? null : $result;
    }

    private function nstring(?Token $token): ?string
    {
        if ($token === null || $token->isNil()) {
            return null;
        }
        return $token->stringValue();
    }

    private function parseDateSafe(string $value): ?ImapDateTime
    {
        try {
            return ImapDateTime::fromDateTime($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
