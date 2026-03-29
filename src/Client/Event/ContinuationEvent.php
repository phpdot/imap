<?php
/**
 * Client event emitted when server sends a continuation request.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

declare(strict_types=1);

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
