<?php
/**
 * Error codes for IMAP protocol parse failures.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

declare(strict_types=1);

namespace PHPdot\Mail\IMAP\Exception;

enum ParseErrorCode: string
{
    case UnexpectedToken = 'UNEXPECTED_TOKEN';
    case UnterminatedString = 'UNTERMINATED_STRING';
    case UnterminatedList = 'UNTERMINATED_LIST';
    case UnterminatedSection = 'UNTERMINATED_SECTION';
    case UnterminatedLiteral = 'UNTERMINATED_LITERAL';
    case InvalidLiteralSize = 'INVALID_LITERAL_SIZE';
    case MaxDepthExceeded = 'MAX_DEPTH_EXCEEDED';
    case InvalidSequenceSet = 'INVALID_SEQUENCE_SET';
    case InvalidPartial = 'INVALID_PARTIAL';
    case InvalidDate = 'INVALID_DATE';
    case UnexpectedEndOfInput = 'UNEXPECTED_END_OF_INPUT';
    case InvalidCommand = 'INVALID_COMMAND';
    case InvalidResponse = 'INVALID_RESPONSE';
    case InvalidCharacter = 'INVALID_CHARACTER';
}
