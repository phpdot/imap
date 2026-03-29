<?php
/**
 * Generates sequential IMAP command tags: A001, A002, ..., Z999.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

declare(strict_types=1);

namespace PHPdot\Mail\IMAP\Client;

use PHPdot\Mail\IMAP\DataType\ValueObject\Tag;

/**
 * Generates sequential IMAP command tags: A001, A002, ..., A999, B001, ...
 */
final class TagGenerator
{
    private string $prefix = 'A';
    private int $counter = 0;

    public function next(): Tag
    {
        $this->counter++;
        if ($this->counter > 999) {
            $this->counter = 1;
            $nextOrd = ord($this->prefix) + 1;
            $this->prefix = $nextOrd > ord('Z') ? 'A' : chr($nextOrd);
        }
        return new Tag(sprintf('%s%03d', $this->prefix, $this->counter));
    }

    public function reset(): void
    {
        $this->prefix = 'A';
        $this->counter = 0;
    }
}
