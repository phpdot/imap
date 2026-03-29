<?php
/**
 * Parsed IMAP untagged data response with type detection.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

declare(strict_types=1);

namespace PHPdot\Mail\IMAP\Client\Response;

use PHPdot\Mail\IMAP\DataType\DTO\Token;

/**
 * Untagged data response: * <data>
 *
 * Covers: EXISTS, RECENT, EXPUNGE, FETCH, FLAGS, LIST, LSUB,
 * STATUS, SEARCH, ESEARCH, CAPABILITY, ENABLED, NAMESPACE, ID,
 * QUOTA, QUOTAROOT, OK, NO, BAD, BYE.
 */
readonly class DataResponse extends Response
{
    /**
     * @param list<Token> $tokens All tokens after the "*" prefix
     */
    public function __construct(
        public string $type,
        public array $tokens,
        public ?int $number = null,
    ) {}

    public function isExists(): bool
    {
        return $this->type === 'EXISTS';
    }

    public function isRecent(): bool
    {
        return $this->type === 'RECENT';
    }

    public function isExpunge(): bool
    {
        return $this->type === 'EXPUNGE';
    }

    public function isFetch(): bool
    {
        return $this->type === 'FETCH';
    }

    public function isOk(): bool
    {
        return $this->type === 'OK';
    }

    public function isNo(): bool
    {
        return $this->type === 'NO';
    }

    public function isBad(): bool
    {
        return $this->type === 'BAD';
    }

    public function isBye(): bool
    {
        return $this->type === 'BYE';
    }

    public function isCapability(): bool
    {
        return $this->type === 'CAPABILITY';
    }

    public function isFlags(): bool
    {
        return $this->type === 'FLAGS';
    }

    public function isList(): bool
    {
        return $this->type === 'LIST' || $this->type === 'XLIST';
    }

    public function isLsub(): bool
    {
        return $this->type === 'LSUB';
    }

    public function isStatus(): bool
    {
        return $this->type === 'STATUS';
    }

    public function isSearch(): bool
    {
        return $this->type === 'SEARCH';
    }

    public function isEsearch(): bool
    {
        return $this->type === 'ESEARCH';
    }

    public function isNamespace(): bool
    {
        return $this->type === 'NAMESPACE';
    }

    public function isEnabled(): bool
    {
        return $this->type === 'ENABLED';
    }

    public function isId(): bool
    {
        return $this->type === 'ID';
    }

    public function isQuota(): bool
    {
        return $this->type === 'QUOTA';
    }

    public function isQuotaRoot(): bool
    {
        return $this->type === 'QUOTAROOT';
    }
}
