<?php
/**
 * Parsed IMAP SEARCH query tree with NOT/OR operators.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

declare(strict_types=1);

namespace PHPdot\Mail\IMAP\DataType\DTO;

use PHPdot\Mail\IMAP\DataType\Enum\SearchKey;

readonly class SearchQuery
{
    /**
     * @param list<SearchQuery> $children
     */
    public function __construct(
        public SearchKey $key,
        public string|int|null $value = null,
        public ?string $header = null,
        public ?string $operator = null,
        public array $children = [],
    ) {}

    public function isCompound(): bool
    {
        return $this->key === SearchKey::Not
            || $this->key === SearchKey::Or_
            || $this->children !== [];
    }

    public static function all(): self
    {
        return new self(SearchKey::All);
    }

    public static function not(self $child): self
    {
        return new self(SearchKey::Not, children: [$child]);
    }

    public static function or_(self $left, self $right): self
    {
        return new self(SearchKey::Or_, children: [$left, $right]);
    }
}
