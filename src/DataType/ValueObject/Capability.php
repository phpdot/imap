<?php
/**
 * Single IMAP capability with AUTH= prefix detection.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

declare(strict_types=1);

namespace PHPdot\Mail\IMAP\DataType\ValueObject;

use PHPdot\Mail\IMAP\Exception\InvalidArgumentException;

readonly class Capability implements \Stringable
{
    public string $name;
    public ?string $authMechanism;

    public function __construct(string $value)
    {
        if ($value === '') {
            throw new InvalidArgumentException('Capability must not be empty');
        }

        $upper = strtoupper($value);
        if (str_starts_with($upper, 'AUTH=')) {
            $this->name = $upper;
            $this->authMechanism = substr($upper, 5);
        } else {
            $this->name = $upper;
            $this->authMechanism = null;
        }
    }

    public function isAuth(): bool
    {
        return $this->authMechanism !== null;
    }

    public function equals(self $other): bool
    {
        return $this->name === $other->name;
    }

    public function __toString(): string
    {
        return $this->name;
    }
}
