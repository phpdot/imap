<?php
/**
 * Tests for IMAP ABNF character set validation.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

declare(strict_types=1);

namespace PHPdot\Mail\IMAP\Tests\Unit\Protocol\Charset;

use PHPdot\Mail\IMAP\Protocol\Charset\CharacterSet;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CharacterSetTest extends TestCase
{
    #[Test]
    public function atomCharAcceptsValidChars(): void
    {
        // Letters, digits, and non-special printable ASCII
        foreach (str_split('ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789!#$&\'+-./;:<=>?@^_`{|}~') as $ch) {
            // Some of these are atom-specials, filter them
            if (str_contains('(){\\"]*%', $ch)) {
                continue;
            }
            self::assertTrue(
                CharacterSet::isAtomChar(ord($ch)),
                sprintf('Expected "%s" (0x%02X) to be valid atom char', $ch, ord($ch)),
            );
        }
    }

    #[Test]
    public function atomCharRejectsSpecials(): void
    {
        // atom-specials: ( ) { SP CTL % * " \ ]
        foreach (str_split("(){\x20%*\"\\]") as $ch) {
            self::assertFalse(
                CharacterSet::isAtomChar(ord($ch)),
                sprintf('Expected "%s" (0x%02X) to be rejected as atom char', $ch, ord($ch)),
            );
        }
    }

    #[Test]
    public function atomCharRejectsCtl(): void
    {
        for ($i = 0x00; $i <= 0x1F; $i++) {
            self::assertFalse(CharacterSet::isAtomChar($i), sprintf('CTL 0x%02X should be rejected', $i));
        }
        self::assertFalse(CharacterSet::isAtomChar(0x7F), 'DEL (0x7F) should be rejected');
    }

    #[Test]
    public function astringCharIncludesCloseBracket(): void
    {
        // ASTRING-CHAR = ATOM-CHAR / resp-specials ("]")
        self::assertTrue(CharacterSet::isAstringChar(ord(']')));
        self::assertFalse(CharacterSet::isAtomChar(ord(']')));
    }

    #[Test]
    public function textCharAcceptsAllExceptCrLf(): void
    {
        // TEXT-CHAR = any CHAR (0x01-0x7F) except CR and LF
        self::assertTrue(CharacterSet::isTextChar(ord('A')));
        self::assertTrue(CharacterSet::isTextChar(ord(' ')));
        self::assertTrue(CharacterSet::isTextChar(ord('!')));
        self::assertFalse(CharacterSet::isTextChar(0x0D)); // CR
        self::assertFalse(CharacterSet::isTextChar(0x0A)); // LF
        self::assertFalse(CharacterSet::isTextChar(0x00)); // NUL
    }

    #[Test]
    public function tagCharExcludesPlus(): void
    {
        self::assertTrue(CharacterSet::isTagChar(ord('A')));
        self::assertTrue(CharacterSet::isTagChar(ord('0')));
        self::assertTrue(CharacterSet::isTagChar(ord(']'))); // resp-specials allowed
        self::assertFalse(CharacterSet::isTagChar(ord('+'))); // + excluded for tags
    }

    #[Test]
    public function quotedSpecials(): void
    {
        self::assertTrue(CharacterSet::isQuotedSpecial(ord('"')));
        self::assertTrue(CharacterSet::isQuotedSpecial(ord('\\')));
        self::assertFalse(CharacterSet::isQuotedSpecial(ord('A')));
    }

    #[Test]
    public function isValidAtomAcceptsSimpleStrings(): void
    {
        self::assertTrue(CharacterSet::isValidAtom('INBOX'));
        self::assertTrue(CharacterSet::isValidAtom('FETCH'));
        self::assertTrue(CharacterSet::isValidAtom('A001'));
        // \Seen contains backslash which is a quoted-special/atom-special per ABNF
        self::assertFalse(CharacterSet::isValidAtom('\\Seen'));
    }

    #[Test]
    public function isValidAtomRejectsEmptyAndSpecials(): void
    {
        self::assertFalse(CharacterSet::isValidAtom(''));
        self::assertFalse(CharacterSet::isValidAtom('hello world')); // contains space
        self::assertFalse(CharacterSet::isValidAtom('(test)'));
        self::assertFalse(CharacterSet::isValidAtom('"quoted"'));
    }

    #[Test]
    public function verifyReturnsNegativeOneForValid(): void
    {
        self::assertSame(-1, CharacterSet::verify('INBOX', 'atom'));
        self::assertSame(-1, CharacterSet::verify('INBOX]', 'astring'));
        self::assertSame(-1, CharacterSet::verify('Hello World', 'text'));
    }

    #[Test]
    public function verifyReturnsPositionOfInvalidChar(): void
    {
        $pos = CharacterSet::verify('HELLO WORLD', 'atom'); // space at position 5
        self::assertSame(5, $pos);
    }

    #[Test]
    public function helperMethodsWork(): void
    {
        self::assertTrue(CharacterSet::isDigit(ord('0')));
        self::assertTrue(CharacterSet::isDigit(ord('9')));
        self::assertFalse(CharacterSet::isDigit(ord('A')));

        self::assertTrue(CharacterSet::isAlpha(ord('A')));
        self::assertTrue(CharacterSet::isAlpha(ord('z')));
        self::assertFalse(CharacterSet::isAlpha(ord('0')));

        self::assertTrue(CharacterSet::isSp(0x20));
        self::assertTrue(CharacterSet::isCr(0x0D));
        self::assertTrue(CharacterSet::isLf(0x0A));
        self::assertTrue(CharacterSet::isDquote(0x22));
        self::assertTrue(CharacterSet::isCtl(0x00));
        self::assertTrue(CharacterSet::isCtl(0x1F));
        self::assertTrue(CharacterSet::isCtl(0x7F));
        self::assertFalse(CharacterSet::isCtl(0x20));

        self::assertTrue(CharacterSet::isListWildcard(ord('%')));
        self::assertTrue(CharacterSet::isListWildcard(ord('*')));
        self::assertFalse(CharacterSet::isListWildcard(ord('A')));

        self::assertTrue(CharacterSet::isRespSpecial(ord(']')));
        self::assertFalse(CharacterSet::isRespSpecial(ord('[')));
    }
}
