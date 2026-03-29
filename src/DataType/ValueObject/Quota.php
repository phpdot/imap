<?php
/**
 * IMAP QUOTA resource with usage and limit tracking.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

declare(strict_types=1);

namespace PHPdot\Mail\IMAP\DataType\ValueObject;

readonly class Quota
{
    public function __construct(
        public string $root,
        public string $resource,
        public int $usage,
        public int $limit,
    ) {}

    public function usagePercent(): float
    {
        if ($this->limit === 0) {
            return 0.0;
        }
        return ($this->usage / $this->limit) * 100.0;
    }

    public function isOverQuota(): bool
    {
        return $this->usage >= $this->limit;
    }
}
