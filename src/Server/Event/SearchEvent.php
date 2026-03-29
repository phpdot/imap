<?php
declare(strict_types=1);

namespace PHPdot\Mail\IMAP\Server\Event;

use PHPdot\Mail\IMAP\DataType\DTO\SearchQuery;
use PHPdot\Mail\IMAP\Server\Command\SearchCommand;

class SearchEvent extends Event
{
    public function __construct(
        public readonly SearchCommand $searchCommand,
    ) {
        parent::__construct($searchCommand);
    }

    public function isUid(): bool
    {
        return $this->searchCommand->isUid;
    }

    /** @return list<SearchQuery> */
    public function queries(): array
    {
        return $this->searchCommand->queries;
    }

    public function charset(): ?string
    {
        return $this->searchCommand->charset;
    }

    /** @return list<string> */
    public function returnOptions(): array
    {
        return $this->searchCommand->returnOptions;
    }
}
