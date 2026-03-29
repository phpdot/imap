<?php
/**
 * Tests for parsing every IMAP command from wire format.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

declare(strict_types=1);

namespace PHPdot\Mail\IMAP\Tests\Unit\Server\Parser;

use PHPdot\Mail\IMAP\Exception\ParseException;
use PHPdot\Mail\IMAP\Server\Command\AppendCommand;
use PHPdot\Mail\IMAP\Server\Command\AuthenticateCommand;
use PHPdot\Mail\IMAP\Server\Command\CompressCommand;
use PHPdot\Mail\IMAP\Server\Command\CopyMoveCommand;
use PHPdot\Mail\IMAP\Server\Command\EnableCommand;
use PHPdot\Mail\IMAP\Server\Command\FetchCommand;
use PHPdot\Mail\IMAP\Server\Command\IdCommand;
use PHPdot\Mail\IMAP\Server\Command\ListCommand;
use PHPdot\Mail\IMAP\Server\Command\LoginCommand;
use PHPdot\Mail\IMAP\Server\Command\MailboxCommand;
use PHPdot\Mail\IMAP\Server\Command\QuotaCommand;
use PHPdot\Mail\IMAP\Server\Command\RenameCommand;
use PHPdot\Mail\IMAP\Server\Command\SearchCommand;
use PHPdot\Mail\IMAP\Server\Command\SelectCommand;
use PHPdot\Mail\IMAP\Server\Command\SimpleCommand;
use PHPdot\Mail\IMAP\Server\Command\StatusCommand;
use PHPdot\Mail\IMAP\Server\Command\StoreCommand;
use PHPdot\Mail\IMAP\Server\Command\UidExpungeCommand;
use PHPdot\Mail\IMAP\Server\Parser\CommandParser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CommandParserTest extends TestCase
{
    private CommandParser $parser;

    protected function setUp(): void
    {
        $this->parser = new CommandParser();
    }

    #[Test]
    public function parsesLogin(): void
    {
        $cmd = $this->parser->parse('A001 LOGIN smith sesame');
        self::assertInstanceOf(LoginCommand::class, $cmd);
        self::assertSame('A001', $cmd->tag->value);
        self::assertSame('smith', $cmd->userid);
        self::assertSame('sesame', $cmd->password);
    }

    #[Test]
    public function parsesLoginWithQuotedPassword(): void
    {
        $cmd = $this->parser->parse('A001 LOGIN smith "pass word"');
        self::assertInstanceOf(LoginCommand::class, $cmd);
        self::assertSame('pass word', $cmd->password);
    }

    #[Test]
    public function parsesAuthenticate(): void
    {
        $cmd = $this->parser->parse('A001 AUTHENTICATE PLAIN dGVzdAB0ZXN0AHRlc3Q=');
        self::assertInstanceOf(AuthenticateCommand::class, $cmd);
        self::assertSame('PLAIN', $cmd->mechanism);
        self::assertSame('dGVzdAB0ZXN0AHRlc3Q=', $cmd->initialResponse);
    }

    #[Test]
    public function parsesAuthenticateWithoutInitialResponse(): void
    {
        $cmd = $this->parser->parse('A001 AUTHENTICATE PLAIN');
        self::assertInstanceOf(AuthenticateCommand::class, $cmd);
        self::assertNull($cmd->initialResponse);
    }

    #[Test]
    public function parsesSelect(): void
    {
        $cmd = $this->parser->parse('A002 SELECT INBOX');
        self::assertInstanceOf(SelectCommand::class, $cmd);
        self::assertSame('INBOX', $cmd->mailbox->name);
        self::assertFalse($cmd->readOnly);
    }

    #[Test]
    public function parsesExamine(): void
    {
        $cmd = $this->parser->parse('A002 EXAMINE "Sent Items"');
        self::assertInstanceOf(SelectCommand::class, $cmd);
        self::assertSame('Sent Items', $cmd->mailbox->name);
        self::assertTrue($cmd->readOnly);
    }

    #[Test]
    public function parsesCreate(): void
    {
        $cmd = $this->parser->parse('A003 CREATE "New Folder"');
        self::assertInstanceOf(MailboxCommand::class, $cmd);
        self::assertSame('CREATE', $cmd->name);
        self::assertSame('New Folder', $cmd->mailbox->name);
    }

    #[Test]
    public function parsesDelete(): void
    {
        $cmd = $this->parser->parse('A003 DELETE OldFolder');
        self::assertInstanceOf(MailboxCommand::class, $cmd);
        self::assertSame('DELETE', $cmd->name);
    }

    #[Test]
    public function parsesRename(): void
    {
        $cmd = $this->parser->parse('A003 RENAME OldName NewName');
        self::assertInstanceOf(RenameCommand::class, $cmd);
        self::assertSame('OldName', $cmd->from->name);
        self::assertSame('NewName', $cmd->to->name);
    }

    #[Test]
    public function parsesSubscribe(): void
    {
        $cmd = $this->parser->parse('A003 SUBSCRIBE #news');
        self::assertInstanceOf(MailboxCommand::class, $cmd);
        self::assertSame('SUBSCRIBE', $cmd->name);
    }

    #[Test]
    public function parsesList(): void
    {
        $cmd = $this->parser->parse('A003 LIST "" "*"');
        self::assertInstanceOf(ListCommand::class, $cmd);
        self::assertSame('', $cmd->reference);
        self::assertSame('*', $cmd->pattern);
        self::assertFalse($cmd->isLsub);
    }

    #[Test]
    public function parsesListWithSpecialUse(): void
    {
        $cmd = $this->parser->parse('A003 LIST (SPECIAL-USE) "" "*"');
        self::assertInstanceOf(ListCommand::class, $cmd);
        self::assertContains('SPECIAL-USE', $cmd->selectOptions);
    }

    #[Test]
    public function parsesLsub(): void
    {
        $cmd = $this->parser->parse('A003 LSUB "" "*"');
        self::assertInstanceOf(ListCommand::class, $cmd);
        self::assertTrue($cmd->isLsub);
    }

    #[Test]
    public function parsesStatus(): void
    {
        $cmd = $this->parser->parse('A003 STATUS INBOX (MESSAGES UIDNEXT UNSEEN)');
        self::assertInstanceOf(StatusCommand::class, $cmd);
        self::assertSame('INBOX', $cmd->mailbox->name);
        self::assertCount(3, $cmd->attributes);
    }

    #[Test]
    public function parsesAppend(): void
    {
        $msg = "From: test@example.com\r\nSubject: Hi\r\n\r\nBody";
        $input = 'A004 APPEND INBOX (\\Seen) {' . strlen($msg) . "}\r\n" . $msg;
        $cmd = $this->parser->parse($input);
        self::assertInstanceOf(AppendCommand::class, $cmd);
        self::assertSame('INBOX', $cmd->mailbox->name);
        self::assertCount(1, $cmd->flags);
        self::assertSame($msg, $cmd->message);
    }

    #[Test]
    public function parsesFetch(): void
    {
        $cmd = $this->parser->parse('A005 FETCH 1:* (FLAGS ENVELOPE)');
        self::assertInstanceOf(FetchCommand::class, $cmd);
        self::assertSame('1:*', (string) $cmd->sequenceSet);
        self::assertCount(2, $cmd->items);
        self::assertFalse($cmd->isUid);
    }

    #[Test]
    public function parsesUidFetch(): void
    {
        $cmd = $this->parser->parse('A005 UID FETCH 100:200 (FLAGS)');
        self::assertInstanceOf(FetchCommand::class, $cmd);
        self::assertTrue($cmd->isUid);
        self::assertSame('UID FETCH', $cmd->name);
    }

    #[Test]
    public function parsesSearch(): void
    {
        $cmd = $this->parser->parse('A006 SEARCH UNSEEN FROM "Smith"');
        self::assertInstanceOf(SearchCommand::class, $cmd);
        self::assertFalse($cmd->isUid);
        self::assertNotEmpty($cmd->queries);
    }

    #[Test]
    public function parsesUidSearch(): void
    {
        $cmd = $this->parser->parse('A006 UID SEARCH ALL');
        self::assertInstanceOf(SearchCommand::class, $cmd);
        self::assertTrue($cmd->isUid);
    }

    #[Test]
    public function parsesSearchWithCharset(): void
    {
        $cmd = $this->parser->parse('A006 SEARCH CHARSET UTF-8 BODY "test"');
        self::assertInstanceOf(SearchCommand::class, $cmd);
        self::assertSame('UTF-8', $cmd->charset);
    }

    #[Test]
    public function parsesStore(): void
    {
        $cmd = $this->parser->parse('A007 STORE 1:3 +FLAGS (\\Deleted)');
        self::assertInstanceOf(StoreCommand::class, $cmd);
        self::assertSame('1:3', (string) $cmd->sequenceSet);
        self::assertSame('add', $cmd->operation->action());
        self::assertCount(1, $cmd->flags);
    }

    #[Test]
    public function parsesStoreSilent(): void
    {
        $cmd = $this->parser->parse('A007 STORE 1 FLAGS.SILENT (\\Seen)');
        self::assertInstanceOf(StoreCommand::class, $cmd);
        self::assertTrue($cmd->operation->isSilent());
        self::assertSame('set', $cmd->operation->action());
    }

    #[Test]
    public function parsesCopy(): void
    {
        $cmd = $this->parser->parse('A008 COPY 2:4 "Saved Messages"');
        self::assertInstanceOf(CopyMoveCommand::class, $cmd);
        self::assertSame('COPY', $cmd->name);
        self::assertSame('Saved Messages', $cmd->destination->name);
    }

    #[Test]
    public function parsesMove(): void
    {
        $cmd = $this->parser->parse('A008 MOVE 1:3 Trash');
        self::assertInstanceOf(CopyMoveCommand::class, $cmd);
        self::assertSame('MOVE', $cmd->name);
    }

    #[Test]
    public function parsesUidCopy(): void
    {
        $cmd = $this->parser->parse('A008 UID COPY 100:200 Archive');
        self::assertInstanceOf(CopyMoveCommand::class, $cmd);
        self::assertTrue($cmd->isUid);
    }

    #[Test]
    public function parsesSimpleCommands(): void
    {
        foreach (['CAPABILITY', 'NOOP', 'LOGOUT', 'STARTTLS', 'CLOSE', 'UNSELECT', 'EXPUNGE', 'NAMESPACE'] as $name) {
            $cmd = $this->parser->parse('A001 ' . $name);
            self::assertInstanceOf(SimpleCommand::class, $cmd);
            self::assertSame($name, $cmd->name);
        }
    }

    #[Test]
    public function parsesEnable(): void
    {
        $cmd = $this->parser->parse('A001 ENABLE CONDSTORE UTF8=ACCEPT');
        self::assertInstanceOf(EnableCommand::class, $cmd);
        self::assertCount(2, $cmd->capabilities);
        self::assertSame('CONDSTORE', $cmd->capabilities[0]);
    }

    #[Test]
    public function parsesIdWithParams(): void
    {
        $cmd = $this->parser->parse('A001 ID ("name" "test" "version" "1.0")');
        self::assertInstanceOf(IdCommand::class, $cmd);
        self::assertNotNull($cmd->params);
        self::assertSame('test', $cmd->params['name']);
    }

    #[Test]
    public function parsesIdNil(): void
    {
        $cmd = $this->parser->parse('A001 ID NIL');
        self::assertInstanceOf(IdCommand::class, $cmd);
        self::assertNull($cmd->params);
    }

    #[Test]
    public function parsesCompress(): void
    {
        $cmd = $this->parser->parse('A001 COMPRESS DEFLATE');
        self::assertInstanceOf(CompressCommand::class, $cmd);
        self::assertSame('DEFLATE', $cmd->mechanism);
    }

    #[Test]
    public function parsesGetQuota(): void
    {
        $cmd = $this->parser->parse('A001 GETQUOTA ""');
        self::assertInstanceOf(QuotaCommand::class, $cmd);
        self::assertSame('GETQUOTA', $cmd->name);
    }

    #[Test]
    public function parsesGetQuotaRoot(): void
    {
        $cmd = $this->parser->parse('A001 GETQUOTAROOT INBOX');
        self::assertInstanceOf(QuotaCommand::class, $cmd);
        self::assertSame('GETQUOTAROOT', $cmd->name);
    }

    #[Test]
    public function parsesUidExpunge(): void
    {
        $cmd = $this->parser->parse('A001 UID EXPUNGE 4:7');
        self::assertInstanceOf(UidExpungeCommand::class, $cmd);
        self::assertSame('4:7', (string) $cmd->sequenceSet);
    }

    #[Test]
    public function parsesIdle(): void
    {
        $cmd = $this->parser->parse('A001 IDLE');
        self::assertInstanceOf(SimpleCommand::class, $cmd);
        self::assertSame('IDLE', $cmd->name);
    }

    #[Test]
    public function tooFewTokensThrows(): void
    {
        $this->expectException(ParseException::class);
        $this->parser->parse('A001');
    }
}
