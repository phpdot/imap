<?php

declare(strict_types=1);

/**
 * Matches IMAP LIST wildcard patterns (* and %) against mailbox names.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Mail\IMAP\Protocol;

/**
 * Matches IMAP LIST patterns against mailbox names.
 *
 * '*' matches zero or more characters including the hierarchy delimiter.
 * '%' matches zero or more characters excluding the hierarchy delimiter.
 */
final class FolderMatcher
{
    /**
     * Tests if a mailbox name matches a LIST pattern, INBOX in any case.
     */
    public static function matches(string $name, string $pattern, string $delimiter = '/'): bool
    {
        $pattern = (string) preg_replace(['/\*+/', '/%+/'], ['*', '%'], self::inbox($pattern, $delimiter, true));
        $name = self::inbox($name, $delimiter, false);
        $length = strlen($name);

        $row = array_fill(0, $length + 1, false);
        $row[0] = true;

        for ($p = 0, $patternLength = strlen($pattern); $p < $patternLength; $p++) {
            $c = $pattern[$p];
            $next = array_fill(0, $length + 1, false);
            $next[0] = $row[0] && ($c === '*' || $c === '%');
            $any = $next[0];

            for ($i = 1; $i <= $length; $i++) {
                $next[$i] = match ($c) {
                    '*' => $row[$i] || $next[$i - 1],
                    '%' => $row[$i] || ($next[$i - 1] && !str_contains($delimiter, $name[$i - 1])),
                    default => $row[$i - 1] && $name[$i - 1] === $c,
                };
                $any = $any || $next[$i];
            }

            if (!$any) {
                return false;
            }
            $row = $next;
        }

        return $row[$length];
    }

    /**
     * Filters a list of mailbox paths by a LIST pattern.
     *
     * @param list<string> $paths
     *
     * @return list<string>
     */
    public static function filter(array $paths, string $pattern, string $delimiter = '/'): array
    {
        return array_values(array_filter(
            $paths,
            static fn(string $path): bool => self::matches($path, $pattern, $delimiter),
        ));
    }

    /**
     * Spells a leading INBOX in capitals, so it matches whatever case the name or pattern used.
     */
    private static function inbox(string $value, string $delimiter, bool $pattern): string
    {
        if (strncasecmp($value, 'INBOX', 5) !== 0) {
            return $value;
        }

        $rest = substr($value, 5);
        $follows = $rest === '' || ($delimiter !== '' && str_starts_with($rest, $delimiter))
            || ($pattern && ($rest[0] === '*' || $rest[0] === '%'));

        return $follows ? 'INBOX' . $rest : $value;
    }
}
