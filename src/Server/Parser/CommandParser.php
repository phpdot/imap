<?php
/**
 * Parses raw IMAP command lines into typed Command DTOs.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

declare(strict_types=1);

namespace PHPdot\Mail\IMAP\Server\Parser;

use PHPdot\Mail\IMAP\DataType\DTO\Token;
use PHPdot\Mail\IMAP\DataType\Enum\FetchAttribute;
use PHPdot\Mail\IMAP\DataType\Enum\FetchMacro;
use PHPdot\Mail\IMAP\DataType\Enum\StatusAttribute;
use PHPdot\Mail\IMAP\DataType\Enum\StoreOperation;
use PHPdot\Mail\IMAP\DataType\Enum\TokenType;
use PHPdot\Mail\IMAP\DataType\ValueObject\Flag;
use PHPdot\Mail\IMAP\DataType\ValueObject\Mailbox;
use PHPdot\Mail\IMAP\DataType\ValueObject\SequenceSet;
use PHPdot\Mail\IMAP\DataType\ValueObject\Tag;
use PHPdot\Mail\IMAP\Exception\ParseErrorCode;
use PHPdot\Mail\IMAP\Exception\ParseException;
use PHPdot\Mail\IMAP\Protocol\Search\SearchQueryParser;
use PHPdot\Mail\IMAP\Protocol\Tokenizer;
use PHPdot\Mail\IMAP\Server\Command\AclCommand;
use PHPdot\Mail\IMAP\Server\Command\AppendCommand;
use PHPdot\Mail\IMAP\Server\Command\AuthenticateCommand;
use PHPdot\Mail\IMAP\Server\Command\MetadataCommand;
use PHPdot\Mail\IMAP\Server\Command\Command;
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

/**
 * Parses raw IMAP command lines into typed Command DTOs.
 */
final class CommandParser
{
    private readonly Tokenizer $tokenizer;
    private readonly SearchQueryParser $searchParser;

    public function __construct()
    {
        $this->tokenizer = new Tokenizer();
        $this->searchParser = new SearchQueryParser();
    }

    public function parse(string $line): Command
    {
        $tokens = $this->tokenizer->tokenize($line);

        if (count($tokens) < 2) {
            throw new ParseException(
                'Command must have at least a tag and command name',
                ParseErrorCode::InvalidCommand,
                0,
                $line,
            );
        }

        $tag = new Tag($tokens[0]->stringValue());
        $commandName = strtoupper($tokens[1]->stringValue());
        $args = array_slice($tokens, 2);

        // Handle UID prefix
        $isUid = false;
        if ($commandName === 'UID' && count($args) >= 1) {
            $isUid = true;
            $commandName = strtoupper($args[0]->stringValue());
            $args = array_slice($args, 1);
        }

        return match ($commandName) {
            'LOGIN' => $this->parseLogin($tag, $args),
            'AUTHENTICATE' => $this->parseAuthenticate($tag, $args),
            'SELECT' => $this->parseSelect($tag, $args, false),
            'EXAMINE' => $this->parseSelect($tag, $args, true),
            'CREATE', 'DELETE', 'SUBSCRIBE', 'UNSUBSCRIBE' => $this->parseMailboxCommand($tag, $commandName, $args),
            'RENAME' => $this->parseRename($tag, $args),
            'LIST', 'XLIST' => $this->parseList($tag, $args, false),
            'LSUB' => $this->parseList($tag, $args, true),
            'STATUS' => $this->parseStatus($tag, $args),
            'APPEND' => $this->parseAppend($tag, $args),
            'FETCH' => $this->parseFetch($tag, $args, $isUid),
            'SEARCH' => $this->parseSearch($tag, $args, $isUid),
            'STORE' => $this->parseStore($tag, $args, $isUid),
            'COPY' => $this->parseCopyMove($tag, 'COPY', $args, $isUid),
            'MOVE' => $this->parseCopyMove($tag, 'MOVE', $args, $isUid),
            'EXPUNGE' => $isUid && $args !== []
                ? new UidExpungeCommand($tag, SequenceSet::fromString($args[0]->stringValue()))
                : new SimpleCommand($tag, $isUid ? 'UID EXPUNGE' : 'EXPUNGE'),
            'ENABLE' => new EnableCommand($tag, array_map(
                static fn(Token $t): string => $t->stringValue(),
                $args,
            )),
            'ID' => $this->parseId($tag, $args),
            'COMPRESS' => new CompressCommand($tag, $args !== [] ? $args[0]->stringValue() : 'DEFLATE'),
            'GETQUOTA' => new QuotaCommand($tag, 'GETQUOTA', $args !== [] ? $args[0]->stringValue() : ''),
            'GETQUOTAROOT' => new QuotaCommand($tag, 'GETQUOTAROOT', $args !== [] ? $args[0]->stringValue() : ''),
            'SETQUOTA' => new QuotaCommand($tag, 'SETQUOTA', $args !== [] ? $args[0]->stringValue() : ''),
            'GETACL', 'MYRIGHTS' => new AclCommand($tag, $commandName,
                isset($args[0]) ? $args[0]->stringValue() : ''),
            'SETACL' => new AclCommand($tag, 'SETACL',
                isset($args[0]) ? $args[0]->stringValue() : '',
                isset($args[1]) ? $args[1]->stringValue() : null,
                isset($args[2]) ? $args[2]->stringValue() : null),
            'DELETEACL' => new AclCommand($tag, 'DELETEACL',
                isset($args[0]) ? $args[0]->stringValue() : '',
                isset($args[1]) ? $args[1]->stringValue() : null),
            'LISTRIGHTS' => new AclCommand($tag, 'LISTRIGHTS',
                isset($args[0]) ? $args[0]->stringValue() : '',
                isset($args[1]) ? $args[1]->stringValue() : null),
            'GETMETADATA' => new MetadataCommand($tag, 'GETMETADATA',
                isset($args[0]) ? $args[0]->stringValue() : '',
                array_map(fn(Token $t): string => $t->stringValue(), array_slice($args, 1))),
            'SETMETADATA' => $this->parseSetMetadata($tag, $args),
            default => new SimpleCommand($tag, $isUid ? 'UID ' . $commandName : $commandName),
        };
    }

    /**
     * @param list<Token> $args
     */
    private function parseLogin(Tag $tag, array $args): LoginCommand
    {
        if (count($args) < 2) {
            throw new ParseException('LOGIN requires userid and password', ParseErrorCode::InvalidCommand);
        }
        return new LoginCommand($tag, $args[0]->stringValue(), $args[1]->stringValue());
    }

    /**
     * @param list<Token> $args
     */
    private function parseAuthenticate(Tag $tag, array $args): AuthenticateCommand
    {
        if ($args === []) {
            throw new ParseException('AUTHENTICATE requires mechanism', ParseErrorCode::InvalidCommand);
        }
        $mechanism = strtoupper($args[0]->stringValue());
        $initialResponse = count($args) > 1 ? $args[1]->stringValue() : null;
        return new AuthenticateCommand($tag, $mechanism, $initialResponse);
    }

    /**
     * @param list<Token> $args
     */
    private function parseSelect(Tag $tag, array $args, bool $readOnly): SelectCommand
    {
        if ($args === []) {
            throw new ParseException('SELECT/EXAMINE requires mailbox', ParseErrorCode::InvalidCommand);
        }
        $condstore = false;
        if (count($args) > 1 && $args[1]->isList()) {
            foreach ($args[1]->children as $child) {
                if (strtoupper($child->stringValue()) === 'CONDSTORE') {
                    $condstore = true;
                }
            }
        }
        return new SelectCommand($tag, new Mailbox($args[0]->stringValue()), $readOnly, $condstore);
    }

    /**
     * @param list<Token> $args
     */
    private function parseMailboxCommand(Tag $tag, string $name, array $args): MailboxCommand
    {
        if ($args === []) {
            throw new ParseException($name . ' requires mailbox', ParseErrorCode::InvalidCommand);
        }
        return new MailboxCommand($tag, $name, new Mailbox($args[0]->stringValue()));
    }

    /**
     * @param list<Token> $args
     */
    private function parseRename(Tag $tag, array $args): RenameCommand
    {
        if (count($args) < 2) {
            throw new ParseException('RENAME requires from and to mailbox', ParseErrorCode::InvalidCommand);
        }
        return new RenameCommand($tag, new Mailbox($args[0]->stringValue()), new Mailbox($args[1]->stringValue()));
    }

    /**
     * @param list<Token> $args
     */
    private function parseList(Tag $tag, array $args, bool $isLsub): ListCommand
    {
        $selectOpts = [];
        $returnOpts = [];
        $pos = 0;

        // Optional selection options (...)
        if (isset($args[$pos]) && $args[$pos]->isList()) {
            foreach ($args[$pos]->children as $child) {
                $selectOpts[] = strtoupper($child->stringValue());
            }
            $pos++;
        }

        $reference = isset($args[$pos]) ? $args[$pos]->stringValue() : '';
        $pos++;
        $pattern = isset($args[$pos]) ? $args[$pos]->stringValue() : '*';
        $pos++;

        // Optional RETURN (...)
        if (isset($args[$pos]) && strtoupper($args[$pos]->stringValue()) === 'RETURN' && isset($args[$pos + 1])) {
            $pos++;
            if ($args[$pos]->isList()) {
                foreach ($args[$pos]->children as $child) {
                    $returnOpts[] = strtoupper($child->stringValue());
                }
            }
        }

        return new ListCommand($tag, $reference, $pattern, $selectOpts, $returnOpts, $isLsub);
    }

    /**
     * @param list<Token> $args
     */
    private function parseStatus(Tag $tag, array $args): StatusCommand
    {
        if (count($args) < 2) {
            throw new ParseException('STATUS requires mailbox and attributes', ParseErrorCode::InvalidCommand);
        }

        $mailbox = new Mailbox($args[0]->stringValue());
        $attrs = [];

        $attrTokens = $args[1]->isList() ? $args[1]->children : [$args[1]];
        foreach ($attrTokens as $attrToken) {
            $attr = StatusAttribute::tryFrom(strtoupper($attrToken->stringValue()));
            if ($attr !== null) {
                $attrs[] = $attr;
            }
        }

        return new StatusCommand($tag, $mailbox, $attrs);
    }

    /**
     * @param list<Token> $args
     */
    private function parseAppend(Tag $tag, array $args): AppendCommand
    {
        if ($args === []) {
            throw new ParseException('APPEND requires mailbox', ParseErrorCode::InvalidCommand);
        }

        $mailbox = new Mailbox($args[0]->stringValue());
        $flags = [];
        $date = null;
        $message = '';

        $pos = 1;

        // Optional flags
        if (isset($args[$pos]) && $args[$pos]->isList()) {
            foreach ($args[$pos]->children as $child) {
                $flags[] = new Flag($child->stringValue());
            }
            $pos++;
        }

        // Optional date
        if (isset($args[$pos]) && $args[$pos]->type === TokenType::String_) {
            $val = $args[$pos]->stringValue();
            if (preg_match('/^\d{1,2}-[A-Z][a-z]{2}-\d{4}/i', $val) === 1) {
                $date = $val;
                $pos++;
            }
        }

        // Message literal
        if (isset($args[$pos])) {
            $message = $args[$pos]->stringValue();
        }

        return new AppendCommand($tag, $mailbox, $message, $flags, $date);
    }

    /**
     * @param list<Token> $args
     */
    private function parseFetch(Tag $tag, array $args, bool $isUid): FetchCommand
    {
        if (count($args) < 2) {
            throw new ParseException('FETCH requires sequence-set and items', ParseErrorCode::InvalidCommand);
        }

        $seqSet = SequenceSet::fromString($args[0]->stringValue());

        // Expand macros: ALL, FAST, FULL → individual fetch attributes
        $macro = FetchMacro::tryFrom(strtoupper($args[1]->stringValue()));
        if ($macro !== null) {
            $items = array_map(
                static fn(FetchAttribute $a): Token => new Token(TokenType::Atom, $a->value),
                $macro->expand(),
            );
        } else {
            $items = $args[1]->isList() ? $args[1]->children : [$args[1]];
        }

        $changedSince = null;
        if (count($args) > 2 && $args[2]->isList()) {
            foreach ($args[2]->children as $i => $child) {
                if (strtoupper($child->stringValue()) === 'CHANGEDSINCE' && isset($args[2]->children[$i + 1])) {
                    $changedSince = $args[2]->children[$i + 1]->intValue();
                }
            }
        }

        return new FetchCommand($tag, $seqSet, $items, $isUid, $changedSince);
    }

    /**
     * @param list<Token> $args
     */
    private function parseSearch(Tag $tag, array $args, bool $isUid): SearchCommand
    {
        $charset = null;
        $returnOpts = [];
        $searchTokens = $args;

        // Check for RETURN (...) at the beginning
        if ($searchTokens !== [] && strtoupper($searchTokens[0]->stringValue()) === 'RETURN'
            && isset($searchTokens[1]) && $searchTokens[1]->isList()) {
            foreach ($searchTokens[1]->children as $child) {
                $returnOpts[] = strtoupper($child->stringValue());
            }
            $searchTokens = array_slice($searchTokens, 2);
        }

        // Check for CHARSET
        if ($searchTokens !== [] && strtoupper($searchTokens[0]->stringValue()) === 'CHARSET'
            && isset($searchTokens[1])) {
            $charset = $searchTokens[1]->stringValue();
            $searchTokens = array_slice($searchTokens, 2);
        }

        $queries = $this->searchParser->parse($searchTokens);

        return new SearchCommand($tag, $queries, $isUid, $charset, $returnOpts);
    }

    /**
     * @param list<Token> $args
     */
    private function parseStore(Tag $tag, array $args, bool $isUid): StoreCommand
    {
        if (count($args) < 3) {
            throw new ParseException('STORE requires sequence-set, action, and flags', ParseErrorCode::InvalidCommand);
        }

        $seqSet = SequenceSet::fromString($args[0]->stringValue());

        // Check for UNCHANGEDSINCE modifier
        $unchangedSince = null;
        $actionPos = 1;
        if ($args[1]->isList()) {
            foreach ($args[1]->children as $i => $child) {
                if (strtoupper($child->stringValue()) === 'UNCHANGEDSINCE' && isset($args[1]->children[$i + 1])) {
                    $unchangedSince = $args[1]->children[$i + 1]->intValue();
                }
            }
            $actionPos = 2;
        }

        $actionStr = strtoupper($args[$actionPos]->stringValue());
        $operation = StoreOperation::from($actionStr);

        $flagTokens = isset($args[$actionPos + 1]) && $args[$actionPos + 1]->isList()
            ? $args[$actionPos + 1]->children
            : (isset($args[$actionPos + 1]) ? [$args[$actionPos + 1]] : []);

        $flags = array_map(
            static fn(Token $t): Flag => new Flag($t->stringValue()),
            $flagTokens,
        );

        return new StoreCommand($tag, $seqSet, $operation, $flags, $isUid, $unchangedSince);
    }

    /**
     * @param list<Token> $args
     */
    private function parseCopyMove(Tag $tag, string $name, array $args, bool $isUid): CopyMoveCommand
    {
        if (count($args) < 2) {
            throw new ParseException($name . ' requires sequence-set and mailbox', ParseErrorCode::InvalidCommand);
        }
        return new CopyMoveCommand(
            $tag,
            $name,
            SequenceSet::fromString($args[0]->stringValue()),
            new Mailbox($args[1]->stringValue()),
            $isUid,
        );
    }

    /**
     * @param list<Token> $args
     */
    private function parseId(Tag $tag, array $args): IdCommand
    {
        if ($args === [] || $args[0]->isNil()) {
            return new IdCommand($tag);
        }

        $params = [];
        if ($args[0]->isList()) {
            $children = $args[0]->children;
            for ($i = 0, $len = count($children); $i + 1 < $len; $i += 2) {
                $key = $children[$i]->stringValue();
                $value = $children[$i + 1]->stringValue();
                $params[$key] = $value;
            }
        }

        return new IdCommand($tag, $params);
    }

    /**
     * @param list<Token> $args
     */
    private function parseSetMetadata(Tag $tag, array $args): MetadataCommand
    {
        $mailbox = isset($args[0]) ? $args[0]->stringValue() : '';
        $values = [];

        // SETMETADATA mailbox (entry1 value1 entry2 value2 ...)
        if (isset($args[1]) && $args[1]->isList()) {
            $children = $args[1]->children;
            for ($i = 0, $len = count($children); $i + 1 < $len; $i += 2) {
                $entry = $children[$i]->stringValue();
                $value = $children[$i + 1]->isNil() ? null : $children[$i + 1]->stringValue();
                $values[$entry] = $value;
            }
        }

        return new MetadataCommand($tag, 'SETMETADATA', $mailbox, array_keys($values), $values);
    }
}
