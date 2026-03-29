<?php
/**
 * IMAP mailbox name value object with INBOX case normalization.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

declare(strict_types=1);

namespace PHPdot\Mail\IMAP\DataType\ValueObject;

readonly class Mailbox implements \Stringable
{
    public string $name;
    public bool $isInbox;

    private const int MAX_NAME_LENGTH = 512;

    public function __construct(string $name)
    {
        // Reject control characters (0x00-0x1F, 0x7F) in mailbox names
        if (preg_match('/[\x00-\x1f\x7f]/', $name) === 1) {
            throw new \PHPdot\Mail\IMAP\Exception\InvalidArgumentException(
                'Mailbox name contains control characters',
            );
        }

        // Reject excessively long names
        if (strlen($name) > self::MAX_NAME_LENGTH) {
            throw new \PHPdot\Mail\IMAP\Exception\InvalidArgumentException(
                sprintf('Mailbox name exceeds maximum length of %d characters', self::MAX_NAME_LENGTH),
            );
        }

        $this->isInbox = strcasecmp($name, 'INBOX') === 0;
        $this->name = $this->isInbox ? 'INBOX' : $name;
    }

    public function equals(self $other): bool
    {
        if ($this->isInbox && $other->isInbox) {
            return true;
        }
        return $this->name === $other->name;
    }

    public function parent(string $delimiter): ?self
    {
        $pos = strrpos($this->name, $delimiter);
        if ($pos === false) {
            return null;
        }
        return new self(substr($this->name, 0, $pos));
    }

    public function __toString(): string
    {
        return $this->name;
    }
}
