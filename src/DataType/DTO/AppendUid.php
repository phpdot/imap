<?php
/**
 * APPENDUID response code data: uidvalidity and uid.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

declare(strict_types=1);

namespace PHPdot\Mail\IMAP\DataType\DTO;

readonly class AppendUid
{
    public function __construct(
        public int $uidValidity,
        public int $uid,
    ) {}
}
