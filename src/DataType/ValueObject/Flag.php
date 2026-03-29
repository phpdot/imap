<?php
/**
 * IMAP flag value object supporting system flags and keywords.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

declare(strict_types=1);

namespace PHPdot\Mail\IMAP\DataType\ValueObject;

use PHPdot\Mail\IMAP\DataType\Enum\SystemFlag;
use PHPdot\Mail\IMAP\Exception\InvalidArgumentException;

readonly class Flag implements \Stringable
{
    public string $value;
    public ?SystemFlag $systemFlag;

    public function __construct(string $value)
    {
        if ($value === '') {
            throw new InvalidArgumentException('Flag must not be empty');
        }

        $this->systemFlag = SystemFlag::tryFromCaseInsensitive($value);

        if ($this->systemFlag !== null) {
            $this->value = $this->systemFlag->value;
        } else {
            $this->value = $value;
        }
    }

    public function isSystem(): bool
    {
        return $this->systemFlag !== null;
    }

    public function isKeyword(): bool
    {
        return $this->systemFlag === null && $this->value[0] !== '\\';
    }

    public function __toString(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return strcasecmp($this->value, $other->value) === 0;
    }
}
