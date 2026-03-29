<?php
/**
 * Tests for IMAP LIST wildcard pattern matching.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

declare(strict_types=1);

namespace PHPdot\Mail\IMAP\Tests\Unit\Protocol;

use PHPdot\Mail\IMAP\Protocol\FolderMatcher;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FolderMatcherTest extends TestCase
{
    #[Test]
    public function starMatchesEverything(): void
    {
        self::assertTrue(FolderMatcher::matches('INBOX', '*'));
        self::assertTrue(FolderMatcher::matches('Sent/2024', '*'));
        self::assertTrue(FolderMatcher::matches('A/B/C/D', '*'));
    }

    #[Test]
    public function percentMatchesOneLevel(): void
    {
        self::assertTrue(FolderMatcher::matches('INBOX', '%'));
        self::assertTrue(FolderMatcher::matches('Sent', '%'));
        self::assertFalse(FolderMatcher::matches('Sent/2024', '%'));
    }

    #[Test]
    public function prefixWithStar(): void
    {
        self::assertTrue(FolderMatcher::matches('INBOX', 'INBOX*'));
        self::assertTrue(FolderMatcher::matches('INBOX/Subfolder', 'INBOX*'));
        self::assertFalse(FolderMatcher::matches('Sent', 'INBOX*'));
    }

    #[Test]
    public function prefixWithPercent(): void
    {
        self::assertTrue(FolderMatcher::matches('INBOX', 'INBOX%'));
        self::assertFalse(FolderMatcher::matches('INBOX/Sub', 'INBOX%'));
    }

    #[Test]
    public function wildcardInMiddle(): void
    {
        self::assertTrue(FolderMatcher::matches('Archive/2024/Jan', 'Archive/*/Jan'));
        self::assertTrue(FolderMatcher::matches('Archive/2024/Sub/Jan', 'Archive/*/Jan'));
        self::assertFalse(FolderMatcher::matches('Archive/2024/Feb', 'Archive/*/Jan'));
    }

    #[Test]
    public function percentInMiddle(): void
    {
        self::assertTrue(FolderMatcher::matches('Archive/2024', 'Archive/%'));
        self::assertFalse(FolderMatcher::matches('Archive/2024/Jan', 'Archive/%'));
    }

    #[Test]
    public function filterByPattern(): void
    {
        $folders = ['INBOX', 'Sent', 'Sent/2024', 'Drafts', 'Trash', 'Archive/2024/Jan'];
        $result = FolderMatcher::filter($folders, '%');
        self::assertSame(['INBOX', 'Sent', 'Drafts', 'Trash'], $result);
    }

    #[Test]
    public function exactMatch(): void
    {
        self::assertTrue(FolderMatcher::matches('INBOX', 'INBOX'));
        self::assertFalse(FolderMatcher::matches('INBOX2', 'INBOX'));
    }

    #[Test]
    public function differentDelimiter(): void
    {
        self::assertTrue(FolderMatcher::matches('INBOX.Subfolder', 'INBOX.%', '.'));
        self::assertFalse(FolderMatcher::matches('INBOX.Sub.folder', 'INBOX.%', '.'));
        self::assertTrue(FolderMatcher::matches('INBOX.Sub.folder', 'INBOX.*', '.'));
    }
}
