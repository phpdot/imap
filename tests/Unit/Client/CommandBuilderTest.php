<?php
/**
 * Tests for building every IMAP client command.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

declare(strict_types=1);

namespace PHPdot\Mail\IMAP\Tests\Unit\Client;

use PHPdot\Mail\IMAP\Client\Command\CommandBuilder;
use PHPdot\Mail\IMAP\DataType\ValueObject\Flag;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CommandBuilderTest extends TestCase
{
    private CommandBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new CommandBuilder();
    }

    #[Test]
    public function capability(): void
    {
        [$tag, $bytes] = $this->builder->capability();
        self::assertSame("A001 CAPABILITY\r\n", $bytes);
        self::assertSame('A001', $tag->value);
    }

    #[Test]
    public function noop(): void
    {
        [$tag, $bytes] = $this->builder->noop();
        self::assertStringContainsString('NOOP', $bytes);
    }

    #[Test]
    public function logout(): void
    {
        [$tag, $bytes] = $this->builder->logout();
        self::assertStringContainsString('LOGOUT', $bytes);
    }

    #[Test]
    public function login(): void
    {
        // Reset to get predictable tags
        $builder = new CommandBuilder();
        [$tag, $bytes] = $builder->login('omar', 'secret');
        self::assertSame("A001 LOGIN omar secret\r\n", $bytes);
    }

    #[Test]
    public function loginQuotedPassword(): void
    {
        $builder = new CommandBuilder();
        [$tag, $bytes] = $builder->login('omar', 'pass word');
        self::assertStringContainsString('"pass word"', $bytes);
    }

    #[Test]
    public function authenticate(): void
    {
        $builder = new CommandBuilder();
        [$tag, $bytes] = $builder->authenticate('PLAIN', 'dGVzdA==');
        self::assertStringContainsString('AUTHENTICATE PLAIN dGVzdA==', $bytes);
    }

    #[Test]
    public function select(): void
    {
        $builder = new CommandBuilder();
        [$tag, $bytes] = $builder->select('INBOX');
        self::assertSame("A001 SELECT INBOX\r\n", $bytes);
    }

    #[Test]
    public function selectQuotedMailbox(): void
    {
        $builder = new CommandBuilder();
        [$tag, $bytes] = $builder->select('Sent Items');
        self::assertStringContainsString('"Sent Items"', $bytes);
    }

    #[Test]
    public function examine(): void
    {
        $builder = new CommandBuilder();
        [$tag, $bytes] = $builder->examine('INBOX');
        self::assertStringContainsString('EXAMINE INBOX', $bytes);
    }

    #[Test]
    public function create(): void
    {
        $builder = new CommandBuilder();
        [$tag, $bytes] = $builder->create('NewFolder');
        self::assertStringContainsString('CREATE NewFolder', $bytes);
    }

    #[Test]
    public function delete(): void
    {
        $builder = new CommandBuilder();
        [$tag, $bytes] = $builder->delete('OldFolder');
        self::assertStringContainsString('DELETE OldFolder', $bytes);
    }

    #[Test]
    public function rename(): void
    {
        $builder = new CommandBuilder();
        [$tag, $bytes] = $builder->rename('Old', 'New');
        self::assertStringContainsString('RENAME Old New', $bytes);
    }

    #[Test]
    public function list_(): void
    {
        $builder = new CommandBuilder();
        [$tag, $bytes] = $builder->list('', '*');
        self::assertStringContainsString('LIST "" "*"', $bytes);
    }

    #[Test]
    public function statusCommand(): void
    {
        $builder = new CommandBuilder();
        [$tag, $bytes] = $builder->status('INBOX', ['MESSAGES', 'UNSEEN']);
        self::assertStringContainsString('STATUS INBOX (MESSAGES UNSEEN)', $bytes);
    }

    #[Test]
    public function fetch(): void
    {
        $builder = new CommandBuilder();
        [$tag, $bytes] = $builder->fetch('1:*', '(FLAGS ENVELOPE)');
        self::assertStringContainsString('FETCH 1:* (FLAGS ENVELOPE)', $bytes);
    }

    #[Test]
    public function uidFetch(): void
    {
        $builder = new CommandBuilder();
        [$tag, $bytes] = $builder->uidFetch('100:200', '(FLAGS)');
        self::assertStringContainsString('UID FETCH 100:200 (FLAGS)', $bytes);
    }

    #[Test]
    public function search(): void
    {
        $builder = new CommandBuilder();
        [$tag, $bytes] = $builder->search('UNSEEN FROM "Smith"');
        self::assertStringContainsString('SEARCH UNSEEN FROM "Smith"', $bytes);
    }

    #[Test]
    public function store(): void
    {
        $builder = new CommandBuilder();
        [$tag, $bytes] = $builder->store('1:3', '+FLAGS', '(\\Deleted)');
        self::assertStringContainsString('STORE 1:3 +FLAGS (\\Deleted)', $bytes);
    }

    #[Test]
    public function copy(): void
    {
        $builder = new CommandBuilder();
        [$tag, $bytes] = $builder->copy('2:4', 'Archive');
        self::assertStringContainsString('COPY 2:4 Archive', $bytes);
    }

    #[Test]
    public function move(): void
    {
        $builder = new CommandBuilder();
        [$tag, $bytes] = $builder->move('1:3', 'Trash');
        self::assertStringContainsString('MOVE 1:3 Trash', $bytes);
    }

    #[Test]
    public function idle(): void
    {
        $builder = new CommandBuilder();
        [$tag, $bytes] = $builder->idle();
        self::assertStringContainsString('IDLE', $bytes);
    }

    #[Test]
    public function done(): void
    {
        self::assertSame("DONE\r\n", $this->builder->done());
    }

    #[Test]
    public function namespace_(): void
    {
        $builder = new CommandBuilder();
        [$tag, $bytes] = $builder->namespace();
        self::assertStringContainsString('NAMESPACE', $bytes);
    }

    #[Test]
    public function enable(): void
    {
        $builder = new CommandBuilder();
        [$tag, $bytes] = $builder->enable(['CONDSTORE', 'UTF8=ACCEPT']);
        self::assertStringContainsString('ENABLE CONDSTORE UTF8=ACCEPT', $bytes);
    }

    #[Test]
    public function idWithParams(): void
    {
        $builder = new CommandBuilder();
        [$tag, $bytes] = $builder->id(['name' => 'test', 'version' => '1.0']);
        self::assertStringContainsString('ID (', $bytes);
        self::assertStringContainsString('"name"', $bytes);
    }

    #[Test]
    public function idNil(): void
    {
        $builder = new CommandBuilder();
        [$tag, $bytes] = $builder->id(null);
        self::assertStringContainsString('ID NIL', $bytes);
    }

    #[Test]
    public function compress(): void
    {
        $builder = new CommandBuilder();
        [$tag, $bytes] = $builder->compress();
        self::assertStringContainsString('COMPRESS DEFLATE', $bytes);
    }

    #[Test]
    public function tagAutoIncrements(): void
    {
        $builder = new CommandBuilder();
        [$t1] = $builder->noop();
        [$t2] = $builder->noop();
        [$t3] = $builder->noop();
        self::assertSame('A001', $t1->value);
        self::assertSame('A002', $t2->value);
        self::assertSame('A003', $t3->value);
    }

    #[Test]
    public function appendWithFlags(): void
    {
        $builder = new CommandBuilder();
        [$tag, $bytes] = $builder->append('INBOX', 'test message', [new Flag('\\Seen')]);
        self::assertStringContainsString('APPEND INBOX', $bytes);
        self::assertStringContainsString('\\Seen', $bytes);
    }

    #[Test]
    public function allCommandsEndWithCrlf(): void
    {
        $builder = new CommandBuilder();
        $commands = [
            $builder->capability()[1],
            $builder->noop()[1],
            $builder->logout()[1],
            $builder->login('u', 'p')[1],
            $builder->select('INBOX')[1],
            $builder->search('ALL')[1],
            $builder->idle()[1],
            $builder->namespace()[1],
            $builder->done(),
        ];

        foreach ($commands as $cmd) {
            self::assertStringEndsWith("\r\n", $cmd, 'Command must end with CRLF: ' . trim($cmd));
        }
    }
}
