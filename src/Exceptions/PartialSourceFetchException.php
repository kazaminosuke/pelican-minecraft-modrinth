<?php

namespace Kazaminosuke\ModManager\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Signals that an upstream batch only partially completed.
 *
 * The fallback is safe to render for the current request, but must never be
 * cached as an authoritative batch result.
 */
final class PartialSourceFetchException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly mixed $fallback,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function fallback(): mixed
    {
        return $this->fallback;
    }
}
