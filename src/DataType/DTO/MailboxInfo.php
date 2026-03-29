<?php
/**
 * IMAP LIST response: mailbox name, delimiter, attributes.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

declare(strict_types=1);

namespace PHPdot\Mail\IMAP\DataType\DTO;

use PHPdot\Mail\IMAP\DataType\Enum\MailboxAttribute;
use PHPdot\Mail\IMAP\DataType\ValueObject\Mailbox;

readonly class MailboxInfo
{
    /**
     * @param list<MailboxAttribute> $attributes
     * @param array<string, mixed> $extended
     */
    public function __construct(
        public Mailbox $mailbox,
        public ?string $delimiter,
        public array $attributes = [],
        public array $extended = [],
    ) {}

    public function hasAttribute(MailboxAttribute $attribute): bool
    {
        return in_array($attribute, $this->attributes, true);
    }

    public function isSelectable(): bool
    {
        return !$this->hasAttribute(MailboxAttribute::Noselect)
            && !$this->hasAttribute(MailboxAttribute::NonExistent);
    }

    public function hasChildren(): bool
    {
        return $this->hasAttribute(MailboxAttribute::HasChildren);
    }

    public function specialUse(): ?MailboxAttribute
    {
        $specialUse = [
            MailboxAttribute::All,
            MailboxAttribute::Archive,
            MailboxAttribute::Drafts,
            MailboxAttribute::Flagged,
            MailboxAttribute::Junk,
            MailboxAttribute::Sent,
            MailboxAttribute::Trash,
        ];

        foreach ($this->attributes as $attr) {
            if (in_array($attr, $specialUse, true)) {
                return $attr;
            }
        }

        return null;
    }
}
