<?php

declare(strict_types=1);

/**
 * Compiles ENVELOPE, BODYSTRUCTURE, FETCH responses to IMAP wire format.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

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
    public static function addressList(array|null $addresses): string
    {
        if ($addresses === null || $addresses === []) {
            return 'NIL';
        }
        $items = array_map(self::address(...), $addresses);
        return '(' . implode('', $items) . ')';
    }

    /**
     * Compile a BodyStructure to wire format.
     *
     * Accepts the wrapper or either node kind — multipart children are raw
     * Part|Multipart unions (see BodyStructureMultipart::$parts), so the
     * recursive path hands them in unwrapped.
     */
    public static function bodyStructure(BodyStructure|BodyStructurePart|BodyStructureMultipart $bs, bool $extensible = true): string
    {
        if ($bs instanceof BodyStructure) {
            if ($bs->isMultipart()) {
                $mp = $bs->multipart();
                if ($mp === null) {
                    return 'NIL';
                }
                $bs = $mp;
            } else {
                $part = $bs->part();
                if ($part === null) {
                    return 'NIL';
                }
                $bs = $part;
            }
        }

        if ($bs instanceof BodyStructureMultipart) {
            return self::bodyStructureMultipart($bs, $extensible);
        }

        return self::bodyStructurePart($bs, $extensible);
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

        $items[] = self::quoted(strtoupper($part->type));
        $items[] = self::quoted(strtoupper($part->subtype));
        $items[] = self::bodyFieldParams($part->params);
        $items[] = self::nstring($part->id);
        $items[] = self::nstring($part->description);
        $items[] = self::quoted($part->encoding->value);
        $items[] = (string) $part->size;

        if ($part->isText()) {
            $items[] = (string) ($part->lines ?? 0);
        }

        if ($part->isMessage()) {
            $items[] = $part->envelope !== null ? self::envelope($part->envelope) : 'NIL';
            $items[] = $part->bodyStructure !== null ? self::bodyStructure($part->bodyStructure, $extensible) : 'NIL';
            $items[] = (string) ($part->lines ?? 0);
        }

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

        $bodyParts = '';
        foreach ($mp->parts as $part) {
            $bodyParts .= self::bodyStructure($part, $extensible);
        }
        $items[] = $bodyParts;

        $items[] = self::quoted(strtoupper($mp->subtype));

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

        if ($result->body !== null) {
            $items[] = 'BODY ' . self::bodyStructure($result->body, false);
        }

        foreach ($result->bodySections as $section => $content) {
            $label = 'BODY[' . $section . ']';
            if (isset($result->sectionOrigins[$section])) {
                $label .= '<' . $result->sectionOrigins[$section] . '>';
            }
            $items[] = $label . ' ' . self::literalOrNil($content);
        }

        if ($result->rfc822 !== null) {
            $items[] = 'RFC822 ' . self::literalOrNil($result->rfc822);
        }

        if ($result->rfc822Header !== null) {
            $items[] = 'RFC822.HEADER ' . self::literalOrNil($result->rfc822Header);
        }

        if ($result->rfc822Text !== null) {
            $items[] = 'RFC822.TEXT ' . self::literalOrNil($result->rfc822Text);
        }

        if ($result->modseq !== null) {
            $items[] = 'MODSEQ (' . $result->modseq . ')';
        }

        return '* ' . $result->sequenceNumber . ' FETCH (' . implode(' ', $items) . ")\r\n";
    }

    private static function literalOrNil(string|null $content): string
    {
        return $content === null ? 'NIL' : '{' . strlen($content) . "}\r\n" . $content;
    }

    /**
     * Compile a nullable string (nstring = string / nil).
     */
    public static function nstring(string|null $value): string
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
    public static function bodyFieldParams(BodyFieldParams|null $params): string
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
    public static function bodyDisposition(BodyDisposition|null $dsp): string
    {
        if ($dsp === null) {
            return 'NIL';
        }

        return '(' . self::quoted(strtoupper($dsp->type)) . ' ' . self::bodyFieldParams($dsp->params) . ')';
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
