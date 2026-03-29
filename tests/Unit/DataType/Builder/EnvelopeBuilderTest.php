<?php
/**
 * Tests for IMAP ENVELOPE building from headers.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

declare(strict_types=1);

namespace PHPdot\Mail\IMAP\Tests\Unit\DataType\Builder;

use PHPdot\Mail\IMAP\DataType\Builder\EnvelopeBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EnvelopeBuilderTest extends TestCase
{
    private EnvelopeBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new EnvelopeBuilder();
    }

    #[Test]
    public function buildsFromHeaders(): void
    {
        $envelope = $this->builder->build([
            'Date' => 'Mon, 7 Feb 1994 21:52:25 -0800',
            'From' => 'Fred Foobar <foobar@example.com>',
            'To' => 'mostrstrstrstr@example.com',
            'Subject' => 'afternoon meeting',
            'Message-ID' => '<B27397-0100000@example.com>',
            'In-Reply-To' => '<previous@example.com>',
        ]);

        self::assertSame('Mon, 7 Feb 1994 21:52:25 -0800', $envelope->date);
        self::assertSame('afternoon meeting', $envelope->subject);
        self::assertNotNull($envelope->from);
        self::assertCount(1, $envelope->from);
        self::assertSame('Fred Foobar', $envelope->from[0]->name);
        self::assertSame('foobar', $envelope->from[0]->mailbox);
        self::assertSame('example.com', $envelope->from[0]->host);
        self::assertNotNull($envelope->to);
        self::assertCount(1, $envelope->to);
        self::assertSame('<B27397-0100000@example.com>', $envelope->messageId);
        self::assertSame('<previous@example.com>', $envelope->inReplyTo);
    }

    #[Test]
    public function senderDefaultsToFrom(): void
    {
        $envelope = $this->builder->build([
            'From' => 'test@example.com',
        ]);

        self::assertNotNull($envelope->sender);
        self::assertSame('test', $envelope->sender[0]->mailbox);
    }

    #[Test]
    public function replyToDefaultsToFrom(): void
    {
        $envelope = $this->builder->build([
            'From' => 'test@example.com',
        ]);

        self::assertNotNull($envelope->replyTo);
        self::assertSame('test', $envelope->replyTo[0]->mailbox);
    }

    #[Test]
    public function missingFieldsAreNull(): void
    {
        $envelope = $this->builder->build([]);

        self::assertNull($envelope->date);
        self::assertNull($envelope->subject);
        self::assertNull($envelope->from);
        self::assertNull($envelope->to);
        self::assertNull($envelope->cc);
        self::assertNull($envelope->bcc);
        self::assertNull($envelope->messageId);
    }

    #[Test]
    public function multipleRecipients(): void
    {
        $envelope = $this->builder->build([
            'To' => 'alice@example.com, Bob <bob@example.com>',
        ]);

        self::assertNotNull($envelope->to);
        self::assertCount(2, $envelope->to);
        self::assertSame('alice', $envelope->to[0]->mailbox);
        self::assertSame('Bob', $envelope->to[1]->name);
        self::assertSame('bob', $envelope->to[1]->mailbox);
    }

    #[Test]
    public function ccAndBcc(): void
    {
        $envelope = $this->builder->build([
            'Cc' => 'cc@example.com',
            'Bcc' => 'bcc@example.com',
        ]);

        self::assertNotNull($envelope->cc);
        self::assertNotNull($envelope->bcc);
        self::assertSame('cc', $envelope->cc[0]->mailbox);
        self::assertSame('bcc', $envelope->bcc[0]->mailbox);
    }

    #[Test]
    public function headerLookupIsCaseInsensitive(): void
    {
        $envelope = $this->builder->build([
            'from' => 'test@example.com',
            'subject' => 'Test',
            'message-id' => '<id@test>',
        ]);

        self::assertNotNull($envelope->from);
        self::assertSame('Test', $envelope->subject);
        self::assertSame('<id@test>', $envelope->messageId);
    }

    #[Test]
    public function addressWithoutAtSign(): void
    {
        $envelope = $this->builder->build([
            'From' => 'localuser',
        ]);

        self::assertNotNull($envelope->from);
        self::assertSame('localuser', $envelope->from[0]->mailbox);
        self::assertNull($envelope->from[0]->host);
    }
}
