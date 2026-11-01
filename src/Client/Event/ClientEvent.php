<?php

declare(strict_types=1);

/**
 * Base class for IMAP client-side events.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Mail\IMAP\Client\Event;

use PHPdot\Mail\IMAP\Client\Response\Response;

/**
 * Base class for all client-side events emitted when server responses arrive.
 */
abstract readonly class ClientEvent
{
    public function __construct(
        public Response $response,
    ) {}
}
