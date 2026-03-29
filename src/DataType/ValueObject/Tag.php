<?php
/**
 * IMAP command tag value object with validation.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

declare(strict_types=1);

namespace PHPdot\Mail\IMAP\DataType\ValueObject;

use PHPdot\Mail\IMAP\Exception\InvalidArgumentException;

readonly class Tag implements \Stringable
{
    public string $value;

    public function __construct(string $value)
    {
        if ($value === '') {
            throw new InvalidArgumentException('Tag must not be empty');
        }

        if ($value === '*' || $value === '+') {
            $this->value = $value;
            return;
        }

        for ($i = 0, $len = strlen($value); $i < $len; $i++) {
            $ord = ord($value[$i]);
            if ($ord <= 0x1F || $ord === 0x7F || $ord === 0x20) {
                throw new InvalidArgumentException(
                    sprintf('Tag contains invalid character at position %d: 0x%02X', $i, $ord),
                );
            }
            if (str_contains('(){%*"\\]+', $value[$i])) {
                throw new InvalidArgumentException(
                    sprintf('Tag contains atom-special character at position %d: %s', $i, $value[$i]),
                );
            }
        }

        $this->value = $value;
    }

    public function __toString(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function isUntagged(): bool
    {
        return $this->value === '*';
    }

    public function isContinuation(): bool
    {
        return $this->value === '+';
    }
}
