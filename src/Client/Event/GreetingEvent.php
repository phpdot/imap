<?php
/**
 * Client event emitted when server greeting is received.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

declare(strict_types=1);

namespace PHPdot\Mail\IMAP\Client\Event;

use PHPdot\Mail\IMAP\Client\Response\GreetingResponse;

readonly class GreetingEvent extends ClientEvent
{
    public function __construct(
        public GreetingResponse $greeting,
    ) {
        parent::__construct($greeting);
    }
}
