<?php

declare(strict_types=1);

/**
 * BODYSTRUCTURE field parameters (key-value pairs from Content-Type).
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Mail\IMAP\DataType\DTO;

readonly class BodyFieldParams
{
    /**
     * @param array<string, string> $params
     */
    public function __construct(
        public array $params = [],
    ) {}

    public function get(string $key): string|null
    {
        $key = strtoupper($key);
        foreach ($this->params as $k => $v) {
            if (strtoupper($k) === $key) {
                return $v;
            }
        }
        return null;
    }

    public function charset(): string|null
    {
        return $this->get('CHARSET');
    }

    public function name(): string|null
    {
        return $this->get('NAME');
    }

    public function isEmpty(): bool
    {
        return $this->params === [];
    }
}
