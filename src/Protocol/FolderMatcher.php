<?php
/**
 * Matches IMAP LIST wildcard patterns (* and %) against mailbox names.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

declare(strict_types=1);

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
     * Tests if a mailbox name matches a LIST pattern.
     */
    public static function matches(string $name, string $pattern, string $delimiter = '/'): bool
    {
        $regex = self::patternToRegex($pattern, $delimiter);
        return preg_match($regex, $name) === 1;
    }

    /**
     * Filters a list of mailbox paths by a LIST pattern.
     *
     * @param list<string> $paths
     * @return list<string>
     */
    public static function filter(array $paths, string $pattern, string $delimiter = '/'): array
    {
        $regex = self::patternToRegex($pattern, $delimiter);
        return array_values(array_filter(
            $paths,
            static fn(string $path): bool => preg_match($regex, $path) === 1,
        ));
    }

    private static function patternToRegex(string $pattern, string $delimiter): string
    {
        // Collapse consecutive wildcards
        $pattern = (string) preg_replace('/\*+/', '*', $pattern);
        $pattern = (string) preg_replace('/%+/', '%', $pattern);

        $escapedDelimiter = preg_quote($delimiter, '/');
        $regex = '';
        $len = strlen($pattern);

        for ($i = 0; $i < $len; $i++) {
            $c = $pattern[$i];
            if ($c === '*') {
                $regex .= '.*';
            } elseif ($c === '%') {
                $regex .= '[^' . $escapedDelimiter . ']*';
            } else {
                $regex .= preg_quote($c, '/');
            }
        }

        return '/^' . $regex . '$/u';
    }
}
