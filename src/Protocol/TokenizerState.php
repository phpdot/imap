<?php

declare(strict_types=1);

/**
 * Internal states for the IMAP protocol tokenizer.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Mail\IMAP\Protocol;

enum TokenizerState: int
{
    case Normal = 0;
    case Atom = 1;
    case String_ = 2;
    case Literal = 3;
    case Sequence = 4;
    case Partial = 5;
    case Text = 6;
}
