<?php

declare(strict_types=1);

/**
 * Parsed IMAP AUTHENTICATE command: SASL mechanism and optional initial response.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Mail\IMAP\Server\Command;

use PHPdot\Mail\IMAP\DataType\ValueObject\Tag;

readonly class AuthenticateCommand extends Command
{
    public function __construct(
        Tag $tag,
        public string $mechanism,
        public string|null $initialResponse = null,
    ) {
        parent::__construct($tag, 'AUTHENTICATE');
    }
}
