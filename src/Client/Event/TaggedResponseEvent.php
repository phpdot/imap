<?php
/**
 * Client event emitted when a tagged response completes a command.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

declare(strict_types=1);

namespace PHPdot\Mail\IMAP\Client\Event;

use PHPdot\Mail\IMAP\Client\Response\TaggedResponse;

readonly class TaggedResponseEvent extends ClientEvent
{
    public function __construct(
        public TaggedResponse $taggedResponse,
    ) {
        parent::__construct($taggedResponse);
    }
}
