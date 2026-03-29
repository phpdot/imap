<?php
/**
 * IMAP NAMESPACE response: personal, other users, and shared namespaces.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

declare(strict_types=1);

namespace PHPdot\Mail\IMAP\DataType\ValueObject;

readonly class NamespaceSet
{
    /**
     * @param list<Namespace_>|null $personal
     * @param list<Namespace_>|null $otherUsers
     * @param list<Namespace_>|null $shared
     */
    public function __construct(
        public ?array $personal = null,
        public ?array $otherUsers = null,
        public ?array $shared = null,
    ) {}

    public static function simple(string $prefix = '', string $delimiter = '/'): self
    {
        return new self(
            personal: [new Namespace_($prefix, $delimiter)],
        );
    }
}
