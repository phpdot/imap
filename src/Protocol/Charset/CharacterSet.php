<?php
/**
 * IMAP ABNF character set lookup tables for protocol validation.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

declare(strict_types=1);

namespace PHPdot\Mail\IMAP\Protocol\Charset;

/**
 * IMAP4rev2 formal syntax character set definitions (RFC 9051 Section 9).
 *
 * Uses boolean lookup arrays indexed by ord() for O(1) character classification.
 * All arrays are lazy-loaded on first access.
 */
final class CharacterSet
{
    /** @var array<int, bool>|null */
    private static ?array $atomChar = null;

    /** @var array<int, bool>|null */
    private static ?array $astringChar = null;

    /** @var array<int, bool>|null */
    private static ?array $textChar = null;

    /** @var array<int, bool>|null */
    private static ?array $tagChar = null;

    /** @var array<int, bool>|null */
    private static ?array $quotedSpecials = null;

    private const string ATOM_SPECIALS = "(){\x20%*\"\\]";

    /**
     * ATOM-CHAR: any CHAR (0x01-0x7F) except atom-specials.
     * atom-specials = "(" / ")" / "{" / SP / CTL / list-wildcards / quoted-specials / resp-specials
     */
    public static function isAtomChar(int $ord): bool
    {
        $map = self::$atomChar ??= self::buildAtomChar();
        return $map[$ord] ?? false;
    }

    /**
     * ASTRING-CHAR: ATOM-CHAR / resp-specials ("]")
     */
    public static function isAstringChar(int $ord): bool
    {
        $map = self::$astringChar ??= self::buildAstringChar();
        return $map[$ord] ?? false;
    }

    /**
     * TEXT-CHAR: any CHAR (0x01-0x7F) except CR (0x0D) and LF (0x0A)
     */
    public static function isTextChar(int $ord): bool
    {
        $map = self::$textChar ??= self::buildTextChar();
        return $map[$ord] ?? false;
    }

    /**
     * tag: 1*(ASTRING-CHAR except "+")
     */
    public static function isTagChar(int $ord): bool
    {
        $map = self::$tagChar ??= self::buildTagChar();
        return $map[$ord] ?? false;
    }

    /**
     * quoted-specials: DQUOTE / "\"
     */
    public static function isQuotedSpecial(int $ord): bool
    {
        $map = self::$quotedSpecials ??= self::buildQuotedSpecials();
        return $map[$ord] ?? false;
    }

    public static function isListWildcard(int $ord): bool
    {
        return $ord === 0x25 || $ord === 0x2A; // % or *
    }

    public static function isRespSpecial(int $ord): bool
    {
        return $ord === 0x5D; // ]
    }

    public static function isDigit(int $ord): bool
    {
        return $ord >= 0x30 && $ord <= 0x39; // 0-9
    }

    public static function isAlpha(int $ord): bool
    {
        return ($ord >= 0x41 && $ord <= 0x5A) || ($ord >= 0x61 && $ord <= 0x7A);
    }

    public static function isCr(int $ord): bool
    {
        return $ord === 0x0D;
    }

    public static function isLf(int $ord): bool
    {
        return $ord === 0x0A;
    }

    public static function isSp(int $ord): bool
    {
        return $ord === 0x20;
    }

    public static function isCtl(int $ord): bool
    {
        return $ord <= 0x1F || $ord === 0x7F;
    }

    public static function isDquote(int $ord): bool
    {
        return $ord === 0x22;
    }

    /**
     * Checks if a string can be represented as an atom (no quoting needed).
     */
    public static function isValidAtom(string $str): bool
    {
        if ($str === '') {
            return false;
        }

        for ($i = 0, $len = strlen($str); $i < $len; $i++) {
            if (!self::isAtomChar(ord($str[$i]))) {
                return false;
            }
        }

        return true;
    }

    /**
     * Returns the position of the first character that is not valid in the given character class,
     * or -1 if the entire string is valid.
     */
    public static function verify(string $str, string $charClass): int
    {
        for ($i = 0, $len = strlen($str); $i < $len; $i++) {
            $ord = ord($str[$i]);
            $valid = match ($charClass) {
                'atom' => self::isAtomChar($ord),
                'astring' => self::isAstringChar($ord),
                'text' => self::isTextChar($ord),
                'tag' => self::isTagChar($ord),
                default => false,
            };
            if (!$valid) {
                return $i;
            }
        }
        return -1;
    }

    /**
     * @return array<int, bool>
     */
    private static function buildAtomChar(): array
    {
        $map = [];
        $exclude = self::ATOM_SPECIALS;

        for ($i = 0x01; $i <= 0x7F; $i++) {
            if ($i <= 0x1F || $i === 0x7F) {
                continue; // CTL
            }
            $ch = chr($i);
            if (str_contains($exclude, $ch)) {
                continue;
            }
            $map[$i] = true;
        }

        return $map;
    }

    /**
     * @return array<int, bool>
     */
    private static function buildAstringChar(): array
    {
        $map = self::$atomChar ?? self::buildAtomChar();
        $map[0x5D] = true; // ] (resp-specials)
        return $map;
    }

    /**
     * @return array<int, bool>
     */
    private static function buildTextChar(): array
    {
        $map = [];
        for ($i = 0x01; $i <= 0x7F; $i++) {
            if ($i === 0x0D || $i === 0x0A) {
                continue; // CR, LF
            }
            $map[$i] = true;
        }
        return $map;
    }

    /**
     * @return array<int, bool>
     */
    private static function buildTagChar(): array
    {
        $map = self::$astringChar ?? self::buildAstringChar();
        unset($map[0x2B]); // remove +
        return $map;
    }

    /**
     * @return array<int, bool>
     */
    private static function buildQuotedSpecials(): array
    {
        $map = [];
        $map[0x22] = true; // "
        $map[0x5C] = true; // \
        return $map;
    }
}
