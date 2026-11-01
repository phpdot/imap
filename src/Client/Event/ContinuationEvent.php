<?php

declare(strict_types=1);

/**
 * Client event emitted when server sends a continuation request.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Mail\IMAP\Client\Event;

use PHPdot\Mail\IMAP\Client\Response\ContinuationResponse;

readonly class ContinuationEvent extends ClientEvent
{
    public function __construct(
        public ContinuationResponse $continuation,
    ) {
        parent::__construct($continuation);
    }
}
