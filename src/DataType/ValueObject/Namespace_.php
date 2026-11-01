<?php

declare(strict_types=1);

/**
 * Single IMAP namespace descriptor with prefix and delimiter.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Mail\IMAP\DataType\ValueObject;

readonly class Namespace_ implements \Stringable
{
    public function __construct(
        public string $prefix,
        public string|null $delimiter,
    ) {}

    public function __toString(): string
    {
        $delim = $this->delimiter !== null
            ? sprintf('"%s"', $this->delimiter)
            : 'NIL';
        return sprintf('("%s" %s)', $this->prefix, $delim);
    }
}
