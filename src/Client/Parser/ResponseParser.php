<?php
/**
 * Parses IMAP server response lines into typed Response DTOs.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

declare(strict_types=1);

namespace PHPdot\Mail\IMAP\Client\Parser;

use PHPdot\Mail\IMAP\Client\Response\ContinuationResponse;
use PHPdot\Mail\IMAP\Client\Response\DataResponse;
use PHPdot\Mail\IMAP\Client\Response\GreetingResponse;
use PHPdot\Mail\IMAP\Client\Response\Response;
use PHPdot\Mail\IMAP\Client\Response\TaggedResponse;
use PHPdot\Mail\IMAP\DataType\DTO\ResponseText;
use PHPdot\Mail\IMAP\DataType\Enum\GreetingStatus;
use PHPdot\Mail\IMAP\DataType\Enum\ResponseCode;
use PHPdot\Mail\IMAP\DataType\Enum\ResponseStatus;
use PHPdot\Mail\IMAP\DataType\ValueObject\Tag;
use PHPdot\Mail\IMAP\Protocol\Tokenizer;

/**
 * Parses server response lines into typed Response DTOs.
 */
final class ResponseParser
{
    private readonly Tokenizer $tokenizer;
    private bool $greetingReceived = false;

    public function __construct()
    {
        $this->tokenizer = new Tokenizer();
    }

    public function parse(string $line): Response
    {
        $line = rtrim($line, "\r\n");

        // Continuation response
        if (str_starts_with($line, '+ ') || $line === '+') {
            return new ContinuationResponse(substr($line, 2));
        }

        // Untagged response
        if (str_starts_with($line, '* ')) {
            return $this->parseUntagged(substr($line, 2));
        }

        // Tagged response
        return $this->parseTagged($line);
    }

    private function parseUntagged(string $content): Response
    {
        // Check for greeting (first untagged response)
        if (!$this->greetingReceived) {
            $greeting = $this->tryParseGreeting($content);
            if ($greeting !== null) {
                $this->greetingReceived = true;
                return $greeting;
            }
        }

        // BYE
        if (str_starts_with(strtoupper($content), 'BYE ')) {
            $tokens = $this->tokenizer->tokenize($content);
            return new DataResponse('BYE', array_slice($tokens, 1));
        }

        // Number-prefixed: N EXISTS, N RECENT, N EXPUNGE, N FETCH
        if (preg_match('/^(\d+)\s+(\S+)(.*)$/s', $content, $matches) === 1) {
            $number = (int) $matches[1];
            $type = strtoupper($matches[2]);

            if (in_array($type, ['EXISTS', 'RECENT', 'EXPUNGE', 'FETCH'], true)) {
                $rest = trim($matches[3]);
                $tokens = $rest !== '' ? $this->tokenizer->tokenize($rest) : [];
                return new DataResponse($type, $tokens, $number);
            }
        }

        // Status: OK, NO, BAD
        $upperContent = strtoupper($content);
        foreach (['OK', 'NO', 'BAD'] as $status) {
            if (str_starts_with($upperContent, $status . ' ') || $upperContent === $status) {
                $text = strlen($content) > strlen($status) + 1 ? substr($content, strlen($status) + 1) : '';
                $tokens = $text !== '' ? $this->tokenizer->tokenize($text) : [];
                return new DataResponse($status, $tokens);
            }
        }

        // Other untagged: CAPABILITY, FLAGS, LIST, STATUS, SEARCH, NAMESPACE, etc.
        $tokens = $this->tokenizer->tokenize($content);
        $type = $tokens !== [] ? strtoupper($tokens[0]->stringValue()) : 'UNKNOWN';

        return new DataResponse($type, array_slice($tokens, 1));
    }

    private function parseTagged(string $line): TaggedResponse
    {
        $spacePos = strpos($line, ' ');
        if ($spacePos === false) {
            return new TaggedResponse(
                new Tag($line),
                ResponseStatus::Bad,
                new ResponseText(text: 'Unparseable response'),
            );
        }

        $tagStr = substr($line, 0, $spacePos);
        $rest = substr($line, $spacePos + 1);

        $tag = new Tag($tagStr);

        // Parse status
        $statusSpacePos = strpos($rest, ' ');
        $statusStr = $statusSpacePos !== false ? substr($rest, 0, $statusSpacePos) : $rest;
        $status = ResponseStatus::tryFrom(strtoupper($statusStr));

        if ($status === null) {
            return new TaggedResponse($tag, ResponseStatus::Bad, new ResponseText(text: $rest));
        }

        $text = $statusSpacePos !== false ? substr($rest, $statusSpacePos + 1) : '';
        return new TaggedResponse($tag, $status, $this->parseResponseText($text));
    }

    private function tryParseGreeting(string $content): ?GreetingResponse
    {
        $upper = strtoupper($content);

        foreach (['OK', 'PREAUTH', 'BYE'] as $statusStr) {
            if (str_starts_with($upper, $statusStr . ' ') || $upper === $statusStr) {
                $status = GreetingStatus::from($statusStr);
                $text = strlen($content) > strlen($statusStr) + 1
                    ? substr($content, strlen($statusStr) + 1)
                    : '';
                return new GreetingResponse($status, $this->parseResponseText($text));
            }
        }

        return null;
    }

    private function parseResponseText(string $text): ResponseText
    {
        $code = null;
        $codeData = [];
        $humanText = $text;

        // Check for [CODE] or [CODE data]
        if (str_starts_with($text, '[')) {
            $closeBracket = strpos($text, ']');
            if ($closeBracket !== false) {
                $codeStr = substr($text, 1, $closeBracket - 1);
                $humanText = trim(substr($text, $closeBracket + 1));

                $codeSpacePos = strpos($codeStr, ' ');
                if ($codeSpacePos !== false) {
                    $codeName = substr($codeStr, 0, $codeSpacePos);
                    $codeDataStr = substr($codeStr, $codeSpacePos + 1);
                    $codeData = explode(' ', $codeDataStr);
                } else {
                    $codeName = $codeStr;
                }

                $code = ResponseCode::tryFrom(strtoupper($codeName));
            }
        }

        return new ResponseText($code, $codeData, $humanText);
    }
}
