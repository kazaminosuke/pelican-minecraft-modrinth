<?php

namespace Kazaminosuke\ModManager\Contracts;

use Kazaminosuke\ModManager\Support\SourceFetchSpec;

/**
 * Optional source capability used by the source-cache executor.
 *
 * Implementations must not perform their own caching. Transport and upstream
 * failures must be thrown so SourceCache can retain stale data and write a
 * short-lived failure marker. A valid empty response should be returned
 * normally.
 */
interface SourceFetchHandlerInterface
{
    /**
     * Execute one uncached source operation.
     *
     * The timeout is an end-to-end budget for all blocking I/O performed by
     * the operation. Implementations are responsible for applying it to the
     * underlying HTTP request(s).
     */
    public function fetchSourceData(SourceFetchSpec $spec, float $timeoutSeconds): mixed;

    /**
     * Return the operation-specific value used when no cached data exists and
     * an inline fetch cannot be completed.
     */
    public function emptySourceData(SourceFetchSpec $spec): mixed;
}
