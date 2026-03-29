<?php
/**
 * Token types produced by the IMAP protocol tokenizer.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

declare(strict_types=1);

namespace PHPdot\Mail\IMAP\DataType\Enum;

enum TokenType: string
{
    case Atom = 'ATOM';
    case String_ = 'STRING';
    case Literal = 'LITERAL';
    case Literal8 = 'LITERAL8';
    case Number = 'NUMBER';
    case Sequence = 'SEQUENCE';
    case Partial = 'PARTIAL';
    case List_ = 'LIST';
    case Section = 'SECTION';
    case Nil = 'NIL';
}
