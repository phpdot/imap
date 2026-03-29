<?php
/**
 * Client event emitted for untagged server data.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

declare(strict_types=1);

namespace PHPdot\Mail\IMAP\Client\Event;

use PHPdot\Mail\IMAP\Client\Response\DataResponse;

readonly class DataEvent extends ClientEvent
{
    public function __construct(
        public DataResponse $data,
    ) {
        parent::__construct($data);
    }

    public function type(): string
    {
        return $this->data->type;
    }
}
