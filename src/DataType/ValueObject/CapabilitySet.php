<?php
/**
 * Collection of IMAP capabilities with lookup and AUTH mechanism queries.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

declare(strict_types=1);

namespace PHPdot\Mail\IMAP\DataType\ValueObject;

readonly class CapabilitySet implements \Countable, \Stringable
{
    /** @var array<string, Capability> */
    private array $capabilities;

    /**
     * @param list<Capability> $capabilities
     */
    public function __construct(array $capabilities = [])
    {
        $map = [];
        foreach ($capabilities as $cap) {
            $map[$cap->name] = $cap;
        }
        $this->capabilities = $map;
    }

    /**
     * @param list<string> $names
     */
    public static function fromArray(array $names): self
    {
        return new self(array_map(
            static fn(string $name): Capability => new Capability($name),
            $names,
        ));
    }

    public function has(string $name): bool
    {
        return isset($this->capabilities[strtoupper($name)]);
    }

    public function hasAuth(string $mechanism): bool
    {
        return $this->has('AUTH=' . strtoupper($mechanism));
    }

    /**
     * @return list<Capability>
     */
    public function all(): array
    {
        return array_values($this->capabilities);
    }

    /**
     * @return list<Capability>
     */
    public function authMechanisms(): array
    {
        return array_values(array_filter(
            $this->capabilities,
            static fn(Capability $c): bool => $c->isAuth(),
        ));
    }

    public function count(): int
    {
        return count($this->capabilities);
    }

    public function merge(self $other): self
    {
        $all = array_merge($this->all(), $other->all());
        return new self($all);
    }

    public function toWireString(): string
    {
        return implode(' ', array_map(
            static fn(Capability $c): string => $c->name,
            array_values($this->capabilities),
        ));
    }

    public function __toString(): string
    {
        return $this->toWireString();
    }
}
