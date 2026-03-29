<?php
/**
 * Parsed ACL commands: GETACL, SETACL, DELETEACL, LISTRIGHTS, MYRIGHTS (RFC 4314).
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

declare(strict_types=1);

namespace PHPdot\Mail\IMAP\Server\Command;

use PHPdot\Mail\IMAP\DataType\ValueObject\Tag;

readonly class AclCommand extends Command
{
    public function __construct(
        Tag $tag,
        string $name,
        public string $mailbox,
        public ?string $identifier = null,
        public ?string $rights = null,
    ) {
        parent::__construct($tag, strtoupper($name));
    }
}
