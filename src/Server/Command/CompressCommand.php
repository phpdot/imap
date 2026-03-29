<?php
/**
 * Parsed IMAP COMPRESS command: compression mechanism.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

declare(strict_types=1);

namespace PHPdot\Mail\IMAP\Server\Command;

use PHPdot\Mail\IMAP\DataType\ValueObject\Tag;

readonly class CompressCommand extends Command
{
    public function __construct(
        Tag $tag,
        public string $mechanism,
    ) {
        parent::__construct($tag, 'COMPRESS');
    }
}
