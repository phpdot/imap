<?php

declare(strict_types=1);

/**
 * IMAP flag value object supporting system flags and keywords.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Mail\IMAP\DataType\ValueObject;

use PHPdot\Mail\IMAP\DataType\Enum\SystemFlag;
use PHPdot\Mail\IMAP\Exception\InvalidArgumentException;
use PHPdot\Mail\IMAP\Protocol\Charset\CharacterSet;

readonly class Flag implements \Stringable
{
    public string $value;
    public SystemFlag|null $systemFlag;

    public function __construct(string $value)
    {
        if ($value === '') {
            throw new InvalidArgumentException('Flag must not be empty');
        }

        if (!self::isValid($value)) {
            throw new InvalidArgumentException('Flag is not a valid IMAP flag');
        }

        $this->systemFlag = SystemFlag::tryFromCaseInsensitive($value);

        if ($this->systemFlag !== null) {
            $this->value = $this->systemFlag->value;
        } else {
            $this->value = $value;
        }
    }

    /**
     * Whether a value is a flag the grammar allows: an atom, a backslash and an atom, or the \* wildcard.
     */
    public static function isValid(string $value): bool
    {
        if ($value === '\\*') {
            return true;
        }

        $atom = str_starts_with($value, '\\') ? substr($value, 1) : $value;

        return $atom !== '' && CharacterSet::isValidAtom($atom);
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
