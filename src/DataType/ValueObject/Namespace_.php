<?php
/**
 * Single IMAP namespace descriptor with prefix and delimiter.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

declare(strict_types=1);

namespace PHPdot\Mail\IMAP\DataType\ValueObject;

readonly class Namespace_ implements \Stringable
{
    public function __construct(
        public string $prefix,
        public ?string $delimiter,
    ) {}

    public function __toString(): string
    {
        $delim = $this->delimiter !== null
            ? sprintf('"%s"', $this->delimiter)
            : 'NIL';
        return sprintf('("%s" %s)', $this->prefix, $delim);
    }
}
