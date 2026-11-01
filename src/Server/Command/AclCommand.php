<?php

declare(strict_types=1);

/**
 * Parsed ACL commands: GETACL, SETACL, DELETEACL, LISTRIGHTS, MYRIGHTS (RFC 4314).
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Mail\IMAP\Server\Command;

use PHPdot\Mail\IMAP\DataType\ValueObject\Tag;

readonly class AclCommand extends Command
{
    public function __construct(
        Tag $tag,
        string $name,
        public string $mailbox,
        public string|null $identifier = null,
        public string|null $rights = null,
    ) {
        parent::__construct($tag, strtoupper($name));
    }
}
