<?php
/**
 * Tests for IMAP capability set operations.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

declare(strict_types=1);

namespace PHPdot\Mail\IMAP\Tests\Unit\DataType\ValueObject;

use PHPdot\Mail\IMAP\DataType\ValueObject\CapabilitySet;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CapabilitySetTest extends TestCase
{
    #[Test]
    public function hasCapability(): void
    {
        $set = CapabilitySet::fromArray(['IMAP4rev2', 'IDLE', 'AUTH=PLAIN']);
        self::assertTrue($set->has('IMAP4rev2'));
        self::assertTrue($set->has('IDLE'));
        self::assertTrue($set->has('AUTH=PLAIN'));
        self::assertFalse($set->has('CONDSTORE'));
    }

    #[Test]
    public function hasCaseInsensitive(): void
    {
        $set = CapabilitySet::fromArray(['IMAP4rev2']);
        self::assertTrue($set->has('imap4rev2'));
    }

    #[Test]
    public function hasAuth(): void
    {
        $set = CapabilitySet::fromArray(['AUTH=PLAIN', 'AUTH=XOAUTH2', 'IDLE']);
        self::assertTrue($set->hasAuth('PLAIN'));
        self::assertTrue($set->hasAuth('XOAUTH2'));
        self::assertFalse($set->hasAuth('GSSAPI'));
    }

    #[Test]
    public function authMechanisms(): void
    {
        $set = CapabilitySet::fromArray(['AUTH=PLAIN', 'IDLE', 'AUTH=XOAUTH2']);
        $mechs = $set->authMechanisms();
        self::assertCount(2, $mechs);
    }

    #[Test]
    public function countCapabilities(): void
    {
        $set = CapabilitySet::fromArray(['A', 'B', 'C']);
        self::assertCount(3, $set);
    }

    #[Test]
    public function merge(): void
    {
        $a = CapabilitySet::fromArray(['IMAP4rev2']);
        $b = CapabilitySet::fromArray(['IDLE']);
        $merged = $a->merge($b);
        self::assertTrue($merged->has('IMAP4rev2'));
        self::assertTrue($merged->has('IDLE'));
    }

    #[Test]
    public function toWireString(): void
    {
        $set = CapabilitySet::fromArray(['IMAP4rev2', 'IDLE']);
        $wire = $set->toWireString();
        self::assertStringContainsString('IMAP4REV2', $wire);
        self::assertStringContainsString('IDLE', $wire);
    }

    #[Test]
    public function deduplicates(): void
    {
        $set = CapabilitySet::fromArray(['IDLE', 'IDLE', 'IDLE']);
        self::assertCount(1, $set);
    }
}
