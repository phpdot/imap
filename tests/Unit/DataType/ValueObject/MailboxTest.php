<?php
/**
 * Tests for IMAP mailbox name normalization.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

declare(strict_types=1);

namespace PHPdot\Mail\IMAP\Tests\Unit\DataType\ValueObject;

use PHPdot\Mail\IMAP\DataType\ValueObject\Mailbox;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MailboxTest extends TestCase
{
    #[Test]
    public function inboxNormalization(): void
    {
        self::assertSame('INBOX', (new Mailbox('INBOX'))->name);
        self::assertSame('INBOX', (new Mailbox('inbox'))->name);
        self::assertSame('INBOX', (new Mailbox('Inbox'))->name);
        self::assertSame('INBOX', (new Mailbox('iNbOx'))->name);
    }

    #[Test]
    public function inboxDetected(): void
    {
        self::assertTrue((new Mailbox('inbox'))->isInbox);
        self::assertFalse((new Mailbox('Sent'))->isInbox);
    }

    #[Test]
    public function nonInboxPreservesCase(): void
    {
        self::assertSame('Sent', (new Mailbox('Sent'))->name);
        self::assertSame('Archive/2024', (new Mailbox('Archive/2024'))->name);
    }

    #[Test]
    public function equalityInbox(): void
    {
        self::assertTrue((new Mailbox('inbox'))->equals(new Mailbox('INBOX')));
    }

    #[Test]
    public function equalityNonInbox(): void
    {
        self::assertTrue((new Mailbox('Sent'))->equals(new Mailbox('Sent')));
        self::assertFalse((new Mailbox('Sent'))->equals(new Mailbox('sent')));
    }

    #[Test]
    public function parentWithDelimiter(): void
    {
        $mb = new Mailbox('Archive/2024/Jan');
        $parent = $mb->parent('/');
        self::assertNotNull($parent);
        self::assertSame('Archive/2024', $parent->name);
    }

    #[Test]
    public function parentOfTopLevel(): void
    {
        $mb = new Mailbox('INBOX');
        self::assertNull($mb->parent('/'));
    }

    #[Test]
    public function stringable(): void
    {
        self::assertSame('INBOX', (string) new Mailbox('inbox'));
        self::assertSame('Sent', (string) new Mailbox('Sent'));
    }
}
