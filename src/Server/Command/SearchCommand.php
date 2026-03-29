<?php
/**
 * Parsed IMAP SEARCH command: queries, charset, return options, UID flag.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

declare(strict_types=1);

namespace PHPdot\Mail\IMAP\Server\Command;

use PHPdot\Mail\IMAP\DataType\DTO\SearchQuery;
use PHPdot\Mail\IMAP\DataType\ValueObject\Tag;

readonly class SearchCommand extends Command
{
    /**
     * @param list<SearchQuery> $queries
     * @param list<string> $returnOptions
     */
    public function __construct(
        Tag $tag,
        public array $queries,
        public bool $isUid = false,
        public ?string $charset = null,
        public array $returnOptions = [],
    ) {
        parent::__construct($tag, $isUid ? 'UID SEARCH' : 'SEARCH');
    }
}
