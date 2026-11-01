<?php

declare(strict_types=1);

/**
 * BODYSTRUCTURE Content-Disposition: type and parameters.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Mail\IMAP\DataType\DTO;

readonly class BodyDisposition
{
    public function __construct(
        public string $type,
        public BodyFieldParams|null $params = null,
    ) {}

    public function isAttachment(): bool
    {
        return strcasecmp($this->type, 'ATTACHMENT') === 0;
    }

    public function isInline(): bool
    {
        return strcasecmp($this->type, 'INLINE') === 0;
    }

    public function filename(): string|null
    {
        return $this->params?->get('FILENAME');
    }
}
