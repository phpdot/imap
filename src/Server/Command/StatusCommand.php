<?php
/**
 * Parsed IMAP STATUS command: mailbox and requested attributes.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

declare(strict_types=1);

namespace PHPdot\Mail\IMAP\Server\Command;

use PHPdot\Mail\IMAP\DataType\Enum\StatusAttribute;
use PHPdot\Mail\IMAP\DataType\ValueObject\Mailbox;
use PHPdot\Mail\IMAP\DataType\ValueObject\Tag;

readonly class StatusCommand extends Command
{
    /**
     * @param list<StatusAttribute> $attributes
     */
    public function __construct(
        Tag $tag,
        public Mailbox $mailbox,
        public array $attributes,
    ) {
        parent::__construct($tag, 'STATUS');
    }
}
