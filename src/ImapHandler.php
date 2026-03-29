<?php
/**
 * Server-side handler registration. Developer registers callbacks for each IMAP command.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

declare(strict_types=1);

namespace PHPdot\Mail\IMAP;

use PHPdot\Mail\IMAP\Connection\ConnectionContext;
use PHPdot\Mail\IMAP\Server\Event\AclEvent;
use PHPdot\Mail\IMAP\Server\Event\AppendEvent;
use PHPdot\Mail\IMAP\Server\Event\AuthenticateEvent;
use PHPdot\Mail\IMAP\Server\Event\CompressEvent;
use PHPdot\Mail\IMAP\Server\Event\CopyEvent;
use PHPdot\Mail\IMAP\Server\Event\FetchEvent;
use PHPdot\Mail\IMAP\Server\Event\IdEvent;
use PHPdot\Mail\IMAP\Server\Event\IdleEvent;
use PHPdot\Mail\IMAP\Server\Event\ListEvent;
use PHPdot\Mail\IMAP\Server\Event\LoginEvent;
use PHPdot\Mail\IMAP\Server\Event\MailboxEvent;
use PHPdot\Mail\IMAP\Server\Event\MetadataEvent;
use PHPdot\Mail\IMAP\Server\Event\MoveEvent;
use PHPdot\Mail\IMAP\Server\Event\QuotaEvent;
use PHPdot\Mail\IMAP\Server\Event\RenameEvent;
use PHPdot\Mail\IMAP\Server\Event\SearchEvent;
use PHPdot\Mail\IMAP\Server\Event\SelectEvent;
use PHPdot\Mail\IMAP\Server\Event\SimpleEvent;
use PHPdot\Mail\IMAP\Server\Event\StatusEvent;
use PHPdot\Mail\IMAP\Server\Event\StoreEvent;
use PHPdot\Mail\IMAP\Server\Event\UidExpungeEvent;

/**
 * Register your IMAP server logic here.
 *
 * Each handler receives a typed Event and the ConnectionContext.
 * Call $event->accept() or $event->reject() to control the response.
 */
final class ImapHandler
{
    /** @var array<string, callable> */
    private array $handlers = [];

    /** @param callable(LoginEvent, ConnectionContext): void $handler */
    public function onLogin(callable $handler): void
    {
        $this->handlers['LOGIN'] = $handler;
    }

    /** @param callable(AuthenticateEvent, ConnectionContext): void $handler */
    public function onAuthenticate(callable $handler): void
    {
        $this->handlers['AUTHENTICATE'] = $handler;
    }

    /** @param callable(SelectEvent, ConnectionContext): void $handler */
    public function onSelect(callable $handler): void
    {
        $this->handlers['SELECT'] = $handler;
    }

    /** @param callable(FetchEvent, ConnectionContext): void $handler */
    public function onFetch(callable $handler): void
    {
        $this->handlers['FETCH'] = $handler;
    }

    /** @param callable(SearchEvent, ConnectionContext): void $handler */
    public function onSearch(callable $handler): void
    {
        $this->handlers['SEARCH'] = $handler;
    }

    /** @param callable(StoreEvent, ConnectionContext): void $handler */
    public function onStore(callable $handler): void
    {
        $this->handlers['STORE'] = $handler;
    }

    /** @param callable(CopyEvent, ConnectionContext): void $handler */
    public function onCopy(callable $handler): void
    {
        $this->handlers['COPY'] = $handler;
    }

    /** @param callable(MoveEvent, ConnectionContext): void $handler */
    public function onMove(callable $handler): void
    {
        $this->handlers['MOVE'] = $handler;
    }

    /** @param callable(AppendEvent, ConnectionContext): void $handler */
    public function onAppend(callable $handler): void
    {
        $this->handlers['APPEND'] = $handler;
    }

    /** @param callable(ListEvent, ConnectionContext): void $handler */
    public function onList(callable $handler): void
    {
        $this->handlers['LIST'] = $handler;
    }

    /** @param callable(ListEvent, ConnectionContext): void $handler */
    public function onLsub(callable $handler): void
    {
        $this->handlers['LSUB'] = $handler;
    }

    /** @param callable(StatusEvent, ConnectionContext): void $handler */
    public function onStatus(callable $handler): void
    {
        $this->handlers['STATUS'] = $handler;
    }

    /** @param callable(MailboxEvent, ConnectionContext): void $handler */
    public function onCreate(callable $handler): void
    {
        $this->handlers['CREATE'] = $handler;
    }

    /** @param callable(MailboxEvent, ConnectionContext): void $handler */
    public function onDelete(callable $handler): void
    {
        $this->handlers['DELETE'] = $handler;
    }

    /** @param callable(RenameEvent, ConnectionContext): void $handler */
    public function onRename(callable $handler): void
    {
        $this->handlers['RENAME'] = $handler;
    }

    /** @param callable(MailboxEvent, ConnectionContext): void $handler */
    public function onSubscribe(callable $handler): void
    {
        $this->handlers['SUBSCRIBE'] = $handler;
    }

    /** @param callable(MailboxEvent, ConnectionContext): void $handler */
    public function onUnsubscribe(callable $handler): void
    {
        $this->handlers['UNSUBSCRIBE'] = $handler;
    }

    /** @param callable(SimpleEvent, ConnectionContext): void $handler */
    public function onExpunge(callable $handler): void
    {
        $this->handlers['EXPUNGE'] = $handler;
    }

    /** @param callable(SimpleEvent, ConnectionContext): void $handler */
    public function onCapability(callable $handler): void
    {
        $this->handlers['CAPABILITY'] = $handler;
    }

    /** @param callable(SimpleEvent, ConnectionContext): void $handler */
    public function onNamespace(callable $handler): void
    {
        $this->handlers['NAMESPACE'] = $handler;
    }

    /** @param callable(IdleEvent, ConnectionContext): void $handler */
    public function onIdle(callable $handler): void
    {
        $this->handlers['IDLE'] = $handler;
    }

    /** @param callable(IdEvent, ConnectionContext): void $handler */
    public function onId(callable $handler): void
    {
        $this->handlers['ID'] = $handler;
    }

    /** @param callable(SimpleEvent, ConnectionContext): void $handler */
    public function onClose(callable $handler): void
    {
        $this->handlers['CLOSE'] = $handler;
    }

    /** @param callable(QuotaEvent, ConnectionContext): void $handler */
    public function onGetQuota(callable $handler): void
    {
        $this->handlers['GETQUOTA'] = $handler;
    }

    /** @param callable(QuotaEvent, ConnectionContext): void $handler */
    public function onGetQuotaRoot(callable $handler): void
    {
        $this->handlers['GETQUOTAROOT'] = $handler;
    }

    /** @param callable(QuotaEvent, ConnectionContext): void $handler */
    public function onSetQuota(callable $handler): void
    {
        $this->handlers['SETQUOTA'] = $handler;
    }

    /** @param callable(UidExpungeEvent, ConnectionContext): void $handler */
    public function onUidExpunge(callable $handler): void
    {
        $this->handlers['UID EXPUNGE'] = $handler;
    }

    /** @param callable(CompressEvent, ConnectionContext): void $handler */
    public function onCompress(callable $handler): void
    {
        $this->handlers['COMPRESS'] = $handler;
    }

    /** @param callable(AclEvent, ConnectionContext): void $handler */
    public function onGetAcl(callable $handler): void
    {
        $this->handlers['GETACL'] = $handler;
    }

    /** @param callable(AclEvent, ConnectionContext): void $handler */
    public function onSetAcl(callable $handler): void
    {
        $this->handlers['SETACL'] = $handler;
    }

    /** @param callable(AclEvent, ConnectionContext): void $handler */
    public function onDeleteAcl(callable $handler): void
    {
        $this->handlers['DELETEACL'] = $handler;
    }

    /** @param callable(AclEvent, ConnectionContext): void $handler */
    public function onMyRights(callable $handler): void
    {
        $this->handlers['MYRIGHTS'] = $handler;
    }

    /** @param callable(AclEvent, ConnectionContext): void $handler */
    public function onListRights(callable $handler): void
    {
        $this->handlers['LISTRIGHTS'] = $handler;
    }

    /** @param callable(MetadataEvent, ConnectionContext): void $handler */
    public function onGetMetadata(callable $handler): void
    {
        $this->handlers['GETMETADATA'] = $handler;
    }

    /** @param callable(MetadataEvent, ConnectionContext): void $handler */
    public function onSetMetadata(callable $handler): void
    {
        $this->handlers['SETMETADATA'] = $handler;
    }

    public function getHandler(string $command): ?callable
    {
        return $this->handlers[strtoupper($command)] ?? null;
    }

    public function hasHandler(string $command): bool
    {
        return isset($this->handlers[strtoupper($command)]);
    }
}
