<?php
/**
 * STORE command operations: FLAGS, +FLAGS, -FLAGS with SILENT variants.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

declare(strict_types=1);

namespace PHPdot\Mail\IMAP\DataType\Enum;

enum StoreOperation: string
{
    case Set = 'FLAGS';
    case SetSilent = 'FLAGS.SILENT';
    case Add = '+FLAGS';
    case AddSilent = '+FLAGS.SILENT';
    case Remove = '-FLAGS';
    case RemoveSilent = '-FLAGS.SILENT';

    public function isSilent(): bool
    {
        return match ($this) {
            self::SetSilent, self::AddSilent, self::RemoveSilent => true,
            default => false,
        };
    }

    public function action(): string
    {
        return match ($this) {
            self::Set, self::SetSilent => 'set',
            self::Add, self::AddSilent => 'add',
            self::Remove, self::RemoveSilent => 'remove',
        };
    }

    public static function fromString(string $value): self
    {
        $upper = strtoupper(trim($value));
        return self::from($upper);
    }
}
