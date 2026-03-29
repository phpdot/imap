<?php
/**
 * Tests for IMAP connection state transitions.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

declare(strict_types=1);

namespace PHPdot\Mail\IMAP\Tests\Unit\Server;

use PHPdot\Mail\IMAP\DataType\Enum\ConnectionState;
use PHPdot\Mail\IMAP\Exception\StateException;
use PHPdot\Mail\IMAP\Protocol\StateMachine\StateMachine;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class StateMachineTest extends TestCase
{
    #[Test]
    public function initialStateNotAuthenticated(): void
    {
        $sm = new StateMachine();
        self::assertSame(ConnectionState::NotAuthenticated, $sm->state());
    }

    #[Test]
    public function anyStateCommandsAlwaysAllowed(): void
    {
        $sm = new StateMachine();
        self::assertTrue($sm->isCommandAllowed('CAPABILITY'));
        self::assertTrue($sm->isCommandAllowed('NOOP'));
        self::assertTrue($sm->isCommandAllowed('LOGOUT'));
    }

    #[Test]
    public function nonAuthCommandsInNotAuthenticated(): void
    {
        $sm = new StateMachine();
        self::assertTrue($sm->isCommandAllowed('LOGIN'));
        self::assertTrue($sm->isCommandAllowed('AUTHENTICATE'));
        self::assertTrue($sm->isCommandAllowed('STARTTLS'));
    }

    #[Test]
    public function authCommandsBlockedInNotAuthenticated(): void
    {
        $sm = new StateMachine();
        self::assertFalse($sm->isCommandAllowed('SELECT'));
        self::assertFalse($sm->isCommandAllowed('LIST'));
    }

    #[Test]
    public function loginTransitionsToAuthenticated(): void
    {
        $sm = new StateMachine();
        $sm->applySuccessTransition('LOGIN');
        self::assertSame(ConnectionState::Authenticated, $sm->state());
    }

    #[Test]
    public function authenticatedAllowsAuthCommands(): void
    {
        $sm = new StateMachine(ConnectionState::Authenticated);
        self::assertTrue($sm->isCommandAllowed('SELECT'));
        self::assertTrue($sm->isCommandAllowed('LIST'));
        self::assertTrue($sm->isCommandAllowed('CREATE'));
        self::assertTrue($sm->isCommandAllowed('NAMESPACE'));
        self::assertTrue($sm->isCommandAllowed('IDLE'));
    }

    #[Test]
    public function authenticatedBlocksSelectedCommands(): void
    {
        $sm = new StateMachine(ConnectionState::Authenticated);
        self::assertFalse($sm->isCommandAllowed('FETCH'));
        self::assertFalse($sm->isCommandAllowed('STORE'));
        self::assertFalse($sm->isCommandAllowed('EXPUNGE'));
    }

    #[Test]
    public function authenticatedBlocksNonAuthCommands(): void
    {
        $sm = new StateMachine(ConnectionState::Authenticated);
        self::assertFalse($sm->isCommandAllowed('LOGIN'));
    }

    #[Test]
    public function selectTransitionsToSelected(): void
    {
        $sm = new StateMachine(ConnectionState::Authenticated);
        $sm->applySuccessTransition('SELECT');
        self::assertSame(ConnectionState::Selected, $sm->state());
    }

    #[Test]
    public function selectedAllowsEverythingExceptNonAuth(): void
    {
        $sm = new StateMachine(ConnectionState::Selected);
        self::assertTrue($sm->isCommandAllowed('FETCH'));
        self::assertTrue($sm->isCommandAllowed('STORE'));
        self::assertTrue($sm->isCommandAllowed('SEARCH'));
        self::assertTrue($sm->isCommandAllowed('COPY'));
        self::assertTrue($sm->isCommandAllowed('MOVE'));
        self::assertTrue($sm->isCommandAllowed('EXPUNGE'));
        self::assertTrue($sm->isCommandAllowed('SELECT')); // re-select
        self::assertTrue($sm->isCommandAllowed('LIST'));    // auth commands also valid
        self::assertFalse($sm->isCommandAllowed('LOGIN'));  // non-auth blocked
    }

    #[Test]
    public function closeTransitionsToAuthenticated(): void
    {
        $sm = new StateMachine(ConnectionState::Selected);
        $sm->applySuccessTransition('CLOSE');
        self::assertSame(ConnectionState::Authenticated, $sm->state());
    }

    #[Test]
    public function unselectTransitionsToAuthenticated(): void
    {
        $sm = new StateMachine(ConnectionState::Selected);
        $sm->applySuccessTransition('UNSELECT');
        self::assertSame(ConnectionState::Authenticated, $sm->state());
    }

    #[Test]
    public function logoutTransitionsToLogout(): void
    {
        $sm = new StateMachine(ConnectionState::Authenticated);
        $sm->applySuccessTransition('LOGOUT');
        self::assertSame(ConnectionState::Logout, $sm->state());
    }

    #[Test]
    public function logoutStateBlocksEverything(): void
    {
        $sm = new StateMachine(ConnectionState::Logout);
        $this->expectException(StateException::class);
        $sm->assertCommandAllowed('NOOP');
    }

    #[Test]
    public function unknownCommandNotAllowed(): void
    {
        $sm = new StateMachine();
        self::assertFalse($sm->isCommandAllowed('XUNKNOWN'));
    }

    #[Test]
    public function assertThrowsOnDisallowed(): void
    {
        $sm = new StateMachine();
        $this->expectException(StateException::class);
        $sm->assertCommandAllowed('FETCH');
    }

    #[Test]
    public function fullSessionFlow(): void
    {
        $sm = new StateMachine();
        self::assertSame(ConnectionState::NotAuthenticated, $sm->state());

        $sm->applySuccessTransition('LOGIN');
        self::assertSame(ConnectionState::Authenticated, $sm->state());

        $sm->applySuccessTransition('SELECT');
        self::assertSame(ConnectionState::Selected, $sm->state());

        $sm->applySuccessTransition('CLOSE');
        self::assertSame(ConnectionState::Authenticated, $sm->state());

        $sm->applySuccessTransition('SELECT');
        self::assertSame(ConnectionState::Selected, $sm->state());

        $sm->applySuccessTransition('LOGOUT');
        self::assertSame(ConnectionState::Logout, $sm->state());
    }

    #[Test]
    public function uidCommandsAllowedInSelected(): void
    {
        $sm = new StateMachine(ConnectionState::Selected);
        self::assertTrue($sm->isCommandAllowed('UID FETCH'));
        self::assertTrue($sm->isCommandAllowed('UID STORE'));
        self::assertTrue($sm->isCommandAllowed('UID COPY'));
        self::assertTrue($sm->isCommandAllowed('UID MOVE'));
        self::assertTrue($sm->isCommandAllowed('UID SEARCH'));
        self::assertTrue($sm->isCommandAllowed('UID EXPUNGE'));
    }

    #[Test]
    public function customCommandRegistration(): void
    {
        $sm = new StateMachine();
        $sm->registry()->register('XCUSTOM', \PHPdot\Mail\IMAP\DataType\Enum\CommandGroup::Any);
        self::assertTrue($sm->isCommandAllowed('XCUSTOM'));
    }
}
