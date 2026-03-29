<?php
/**
 * Applies BODY[]<offset.count> byte range logic to content.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

declare(strict_types=1);

namespace PHPdot\Mail\IMAP\Protocol;

use PHPdot\Mail\IMAP\DataType\ValueObject\Partial;

/**
 * Applies BODY[]<offset.count> byte range logic to content.
 */
final class PartialFetch
{
    /**
     * Applies a partial range to content bytes.
     *
     * @return array{content: string, origin: int}
     */
    public static function apply(string $content, Partial $partial): array
    {
        $totalSize = strlen($content);

        if ($partial->offset >= $totalSize) {
            return ['content' => '', 'origin' => $partial->offset];
        }

        $slice = substr($content, $partial->offset, $partial->count);

        return [
            'content' => $slice,
            'origin' => $partial->offset,
        ];
    }

    /**
     * Applies a partial range specified by offset and count.
     *
     * @return array{content: string, origin: int}
     */
    public static function applyRange(string $content, int $offset, int $count): array
    {
        return self::apply($content, new Partial($offset, $count));
    }
}
