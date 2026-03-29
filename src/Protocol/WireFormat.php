<?php
/**
 * Compiles ENVELOPE, BODYSTRUCTURE, FETCH responses to IMAP wire format.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

declare(strict_types=1);

namespace PHPdot\Mail\IMAP\Protocol;

use PHPdot\Mail\IMAP\DataType\DTO\Address;
use PHPdot\Mail\IMAP\DataType\DTO\BodyDisposition;
use PHPdot\Mail\IMAP\DataType\DTO\BodyFieldParams;
use PHPdot\Mail\IMAP\DataType\DTO\BodyStructure;
use PHPdot\Mail\IMAP\DataType\DTO\BodyStructureMultipart;
use PHPdot\Mail\IMAP\DataType\DTO\BodyStructurePart;
use PHPdot\Mail\IMAP\DataType\DTO\Envelope;
use PHPdot\Mail\IMAP\DataType\DTO\FetchResult;
use PHPdot\Mail\IMAP\DataType\ValueObject\Flag;

/**
 * Compiles IMAP protocol data structures to wire format.
 *
 * Handles: Envelope, BodyStructure, Address, FetchResult — the complex
 * nested structures that RFC 9051 Section 7.5 defines.
 */
final class WireFormat
{
    /**
     * Compile an Envelope to wire format.
     *
     * RFC 9051: envelope = "(" env-date SP env-subject SP env-from SP
     *           env-sender SP env-reply-to SP env-to SP env-cc SP
     *           env-bcc SP env-in-reply-to SP env-message-id ")"
     */
    public static function envelope(Envelope $env): string
    {
        return '(' . implode(' ', [
            self::nstring($env->date),
            self::nstring($env->subject),
            self::addressList($env->from),
            self::addressList($env->sender),
            self::addressList($env->replyTo),
            self::addressList($env->to),
            self::addressList($env->cc),
            self::addressList($env->bcc),
            self::nstring($env->inReplyTo),
            self::nstring($env->messageId),
        ]) . ')';
    }

    /**
     * Compile an Address to wire format.
     *
     * RFC 9051: address = "(" addr-name SP addr-adl SP addr-mailbox SP addr-host ")"
     */
    public static function address(Address $addr): string
    {
        return '(' . implode(' ', [
            self::nstring($addr->name),
            self::nstring($addr->adl),
            self::nstring($addr->mailbox),
            self::nstring($addr->host),
        ]) . ')';
    }

    /**
     * Compile a list of addresses, or NIL if null/empty.
     *
     * @param list<Address>|null $addresses
     */
    public static function addressList(?array $addresses): string
    {
        if ($addresses === null || $addresses === []) {
            return 'NIL';
        }
        $items = array_map(self::address(...), $addresses);
        return '(' . implode('', $items) . ')';
    }

    /**
     * Compile a BodyStructure to wire format.
     */
    public static function bodyStructure(BodyStructure $bs, bool $extensible = true): string
    {
        if ($bs->isMultipart()) {
            $mp = $bs->multipart();
            if ($mp === null) {
                return 'NIL';
            }
            return self::bodyStructureMultipart($mp, $extensible);
        }

        $part = $bs->part();
        if ($part === null) {
            return 'NIL';
        }
        return self::bodyStructurePart($part, $extensible);
    }

    /**
     * Compile a single body part.
     *
     * RFC 9051: body-type-basic = media-basic SP body-fields
     *           body-type-text  = media-text SP body-fields SP body-fld-lines
     *           body-type-msg   = media-message SP body-fields SP envelope SP body SP body-fld-lines
     */
    public static function bodyStructurePart(BodyStructurePart $part, bool $extensible = true): string
    {
        $items = [];

        // body-fields: type subtype params id desc enc octets
        $items[] = self::quoted($part->type);
        $items[] = self::quoted($part->subtype);
        $items[] = self::bodyFieldParams($part->params);
        $items[] = self::nstring($part->id);
        $items[] = self::nstring($part->description);
        $items[] = self::quoted($part->encoding->value);
        $items[] = (string) $part->size;

        // Text type: add line count
        if ($part->isText()) {
            $items[] = (string) ($part->lines ?? 0);
        }

        // Message/RFC822 type: add envelope, body, lines
        if ($part->isMessage()) {
            $items[] = $part->envelope !== null ? self::envelope($part->envelope) : 'NIL';
            $items[] = $part->bodyStructure !== null ? self::bodyStructure($part->bodyStructure, $extensible) : 'NIL';
            $items[] = (string) ($part->lines ?? 0);
        }

        // Extension fields (body-ext-1part): md5 dsp lang loc
        if ($extensible) {
            $items[] = self::nstring($part->md5);
            $items[] = self::bodyDisposition($part->disposition);
            $items[] = self::bodyLanguage($part->language);
            $items[] = self::nstring($part->location);
        }

        return '(' . implode(' ', $items) . ')';
    }

    /**
     * Compile a multipart body structure.
     *
     * RFC 9051: body-type-mpart = 1*body SP media-subtype [SP body-ext-mpart]
     */
    public static function bodyStructureMultipart(BodyStructureMultipart $mp, bool $extensible = true): string
    {
        $items = [];

        // Each child body (no space between them per ABNF: 1*body)
        $bodyParts = '';
        foreach ($mp->parts as $part) {
            $bodyParts .= self::bodyStructure($part, $extensible);
        }
        $items[] = $bodyParts;

        // media-subtype
        $items[] = self::quoted($mp->subtype);

        // Extension fields (body-ext-mpart): params dsp lang loc
        if ($extensible) {
            $items[] = self::bodyFieldParams($mp->params);
            $items[] = self::bodyDisposition($mp->disposition);
            $items[] = self::bodyLanguage($mp->language);
            $items[] = self::nstring($mp->location);
        }

        return '(' . implode(' ', $items) . ')';
    }

    /**
     * Compile a full FETCH response line.
     *
     * RFC 9051: message-data = nz-number SP ("FETCH" SP msg-att)
     *           msg-att = "(" (msg-att-dynamic / msg-att-static) ... ")"
     */
    public static function fetchResponse(FetchResult $result): string
    {
        $items = [];

        if ($result->uid !== null) {
            $items[] = 'UID ' . $result->uid;
        }

        if ($result->flags !== null) {
            $flagStrs = array_map(static fn(Flag $f): string => $f->value, $result->flags);
            $items[] = 'FLAGS (' . implode(' ', $flagStrs) . ')';
        }

        if ($result->internalDate !== null) {
            $items[] = 'INTERNALDATE "' . trim($result->internalDate->toDateTimeString()) . '"';
        }

        if ($result->rfc822Size !== null) {
            $items[] = 'RFC822.SIZE ' . $result->rfc822Size;
        }

        if ($result->envelope !== null) {
            $items[] = 'ENVELOPE ' . self::envelope($result->envelope);
        }

        if ($result->bodyStructure !== null) {
            $items[] = 'BODYSTRUCTURE ' . self::bodyStructure($result->bodyStructure);
        }

        foreach ($result->bodySections as $section => $content) {
            if ($content === null) {
                $items[] = 'BODY[' . $section . '] NIL';
            } else {
                $items[] = 'BODY[' . $section . '] {' . strlen($content) . "}\r\n" . $content;
            }
        }

        if ($result->modseq !== null) {
            $items[] = 'MODSEQ (' . $result->modseq . ')';
        }

        return '* ' . $result->sequenceNumber . ' FETCH (' . implode(' ', $items) . ")\r\n";
    }

    /**
     * Compile a nullable string (nstring = string / nil).
     */
    public static function nstring(?string $value): string
    {
        if ($value === null) {
            return 'NIL';
        }
        return self::quoted($value);
    }

    /**
     * Compile a quoted string with escaping.
     */
    public static function quoted(string $value): string
    {
        // If it contains CR/LF/NUL, use literal
        for ($i = 0, $len = strlen($value); $i < $len; $i++) {
            $ord = ord($value[$i]);
            if ($ord === 0x0D || $ord === 0x0A || $ord === 0x00 || $ord > 0x7F) {
                return '{' . strlen($value) . "}\r\n" . $value;
            }
        }
        $escaped = str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
        return '"' . $escaped . '"';
    }

    /**
     * Compile body field params: "(" string SP string *(SP string SP string) ")" / nil
     */
    public static function bodyFieldParams(?BodyFieldParams $params): string
    {
        if ($params === null || $params->isEmpty()) {
            return 'NIL';
        }

        $items = [];
        foreach ($params->params as $key => $value) {
            $items[] = self::quoted($key);
            $items[] = self::quoted($value);
        }

        return '(' . implode(' ', $items) . ')';
    }

    /**
     * Compile body disposition: "(" string SP body-fld-param ")" / nil
     */
    public static function bodyDisposition(?BodyDisposition $dsp): string
    {
        if ($dsp === null) {
            return 'NIL';
        }

        return '(' . self::quoted($dsp->type) . ' ' . self::bodyFieldParams($dsp->params) . ')';
    }

    /**
     * Compile body language: nstring / "(" string *(SP string) ")"
     *
     * @param list<string>|string|null $language
     */
    public static function bodyLanguage(array|string|null $language): string
    {
        if ($language === null) {
            return 'NIL';
        }
        if (is_string($language)) {
            return self::quoted($language);
        }
        if ($language === []) {
            return 'NIL';
        }
        $items = array_map(self::quoted(...), $language);
        return '(' . implode(' ', $items) . ')';
    }
}
