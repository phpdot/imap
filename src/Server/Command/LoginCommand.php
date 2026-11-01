<?php

declare(strict_types=1);

/**
 * Parsed IMAP LOGIN command: userid and password.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Mail\IMAP\Server\Command;

use PHPdot\Mail\IMAP\DataType\ValueObject\Tag;

readonly class LoginCommand extends Command
{
    public function __construct(
        Tag $tag,
        public string $userid,
        public string $password,
    ) {
        parent::__construct($tag, 'LOGIN');
    }
}
