<?php

declare(strict_types=1);

/**
 * One requested FETCH data item, e.g. FLAGS or BODY.PEEK[HEADER.FIELDS (FROM)]<0.512>.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Mail\IMAP\DataType\DTO;

use PHPdot\Mail\IMAP\DataType\Enum\TokenType;

readonly class FetchItem
{
    /**
     * @param string $name Upper-case item name without section: FLAGS, UID, BODY, BODY.PEEK, BINARY, RFC822.HEADER…
     * @param string|null $section Section spec as sent (without brackets); null when the item has no section.
     *                             `BODY` with a null section is the non-extensible body structure.
     * @param int|null $partialOffset First octet of a `<offset.count>` partial
     * @param int|null $partialCount Maximum octets of a `<offset.count>` partial
     */
    public function __construct(
        public string $name,
        public string|null $section = null,
        public int|null $partialOffset = null,
        public int|null $partialCount = null,
    ) {}

    /**
     * Parse the flat token list of a FETCH command into items, attaching
     * each section and partial token to the item it follows.
     *
     * @param list<Token> $tokens
     *
     * @return list<self>
     */
    public static function fromTokens(array $tokens): array
    {
        $items = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if ($token->type === TokenType::Section || $token->type === TokenType::Partial) {
                continue;
            }

            $name = strtoupper($token->stringValue());
            if ($name === '') {
                continue;
            }

            $section = null;
            $offset = null;
            $length = null;

            if (isset($tokens[$i + 1]) && $tokens[$i + 1]->type === TokenType::Section) {
                $section = $tokens[$i + 1]->stringValue();
                $i++;

                if (isset($tokens[$i + 1]) && $tokens[$i + 1]->type === TokenType::Partial) {
                    $parts = explode('.', $tokens[$i + 1]->stringValue(), 2);
                    $offset = max(0, (int) $parts[0]);
                    $length = isset($parts[1]) ? max(0, (int) $parts[1]) : null;
                    $i++;
                }
            }

            $items[] = new self($name, $section, $offset, $length);
        }

        return $items;
    }

    /**
     * Whether this is a BODY[...] / BODY.PEEK[...] section request.
     */
    public function isBodySection(): bool
    {
        return $this->section !== null && ($this->name === 'BODY' || $this->name === 'BODY.PEEK');
    }

    /**
     * Whether fetching this item must leave \Seen untouched.
     */
    public function isPeek(): bool
    {
        return match ($this->name) {
            'BODY', 'BINARY' => $this->section === null,
            'RFC822', 'RFC822.TEXT' => false,
            default => true,
        };
    }

    public function isPartial(): bool
    {
        return $this->partialOffset !== null;
    }
}
