<?php

namespace Kazaminosuke\ModManager\Support;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Kazaminosuke\ModManager\Contracts\SourceFetchExecutorInterface;
use Kazaminosuke\ModManager\Exceptions\PartialSourceFetchException;
use Kazaminosuke\ModManager\Jobs\RevalidateSourceCache;
use Kazaminosuke\ModManager\Services\InstalledOperationManager;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

final class SourceCache
{
    public const SCHEMA_VERSION = 1;

    private const FAILURE_MARKER_VERSION = 1;

    public function __construct(
        private readonly CacheRepository $cache,
        private readonly InstalledOperationManager $operations,
        private readonly SourceFetchExecutorInterface $executor,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function swr(SourceFetchSpec $spec, CacheProfile $profile): mixed
    {
        $entry = $this->readEntry($spec);

        if ($entry !== null && $entry['fresh_until'] > time()) {
            return $entry['data'];
        }

        if ($this->hasFailureMarker($spec)) {
            return $entry !== null ? $entry['data'] : $this->emptyResult($spec);
        }

        if ($entry !== null && $this->supportsAsyncDispatch()) {
            $this->dispatchRevalidation($spec, $profile);

            return $entry['data'];
        }

        try {
            return $this->fetchAndStore($spec, $profile, $profile->inlineBudgetSeconds());
        } catch (Throwable $exception) {
            $this->markFailure($spec, $profile, $exception);

            if ($entry !== null) {
                return $entry['data'];
            }

            if ($this->supportsAsyncDispatch()) {
                // The marker suppresses subsequent requests, but the request
                // that observed the miss still queues one background attempt.
                $this->dispatchRevalidation($spec, $profile, ignoreFailureMarker: true);
            }

            if ($exception instanceof PartialSourceFetchException) {
                return $exception->fallback();
            }

            return $this->emptyResult($spec);
        }
    }

    /**
     * Fetch data for an authoritative workflow such as an Installed scan.
     *
     * Unlike the render-oriented swr() method, a cold upstream failure is
     * rethrown so callers cannot mistake transport failure for a valid empty
     * result and persist it as resolved metadata.
     */
    public function swrRequired(SourceFetchSpec $spec, CacheProfile $profile): mixed
    {
        $entry = $this->readEntry($spec);

        if ($entry !== null) {
            return $entry['data'];
        }

        if ($this->hasFailureMarker($spec)) {
            throw new RuntimeException("Source [{$spec->sourceKey}] operation [{$spec->operation}] is temporarily unavailable.");
        }

        try {
            return $this->fetchAndStore($spec, $profile, $profile->backgroundTimeoutSeconds());
        } catch (Throwable $exception) {
            $this->markFailure($spec, $profile, $exception);

            if ($this->supportsAsyncDispatch()) {
                $this->dispatchRevalidation($spec, $profile, ignoreFailureMarker: true);
            }

            throw $exception;
        }
    }

    /**
     * Execute a queued refresh. Failures intentionally leave any stale entry
     * untouched and are absorbed after recording a short-lived marker.
     */
    public function revalidate(SourceFetchSpec $spec, CacheProfile $profile): void
    {
        try {
            $this->fetchAndStore($spec, $profile, $profile->backgroundTimeoutSeconds());
        } catch (Throwable $exception) {
            $this->markFailure($spec, $profile, $exception);
        }
    }

    /**
     * Return a cached value without fetching or dispatching.
     *
     * The boolean distinguishes a cached null from a miss and is used by
     * progressive-enrichment render paths.
     *
     * @return array{hit: bool, data: mixed, fresh: bool}
     */
    public function peek(SourceFetchSpec $spec): array
    {
        $entry = $this->readEntry($spec);

        return [
            'hit' => $entry !== null,
            'data' => $entry['data'] ?? null,
            'fresh' => $entry !== null && $entry['fresh_until'] > time(),
        ];
    }

    /**
     * Queue a refresh without performing an inline fetch.
     */
    public function revalidateAsync(SourceFetchSpec $spec, CacheProfile $profile): bool
    {
        if (!$this->supportsAsyncDispatch() || $this->hasFailureMarker($spec)) {
            return false;
        }

        return $this->dispatchRevalidation($spec, $profile);
    }

    /** @return array{v: int, data: mixed, fresh_until: int}|null */
    private function readEntry(SourceFetchSpec $spec): ?array
    {
        $payload = $this->cache->get($spec->cacheKey());

        if (!is_array($payload)
            || ($payload['v'] ?? null) !== self::SCHEMA_VERSION
            || !array_key_exists('data', $payload)
            || !is_int($payload['fresh_until'] ?? null)) {
            return null;
        }

        return [
            'v' => self::SCHEMA_VERSION,
            'data' => $payload['data'],
            'fresh_until' => $payload['fresh_until'],
        ];
    }

    private function fetchAndStore(
        SourceFetchSpec $spec,
        CacheProfile $profile,
        float $timeoutSeconds,
    ): mixed {
        $data = $this->executor->fetch($spec, $timeoutSeconds);
        $entry = [
            'v' => self::SCHEMA_VERSION,
            'data' => $data,
            'fresh_until' => time() + $profile->freshTtlSeconds(),
        ];
        $staleTtl = $profile->staleTtlSeconds();

        if ($staleTtl === null) {
            $this->cache->forever($spec->cacheKey(), $entry);
        } else {
            $this->cache->put($spec->cacheKey(), $entry, $staleTtl);
        }

        $this->cache->forget($this->failureMarkerKey($spec));

        return $data;
    }

    private function emptyResult(SourceFetchSpec $spec): mixed
    {
        try {
            return $this->executor->emptyResult($spec);
        } catch (Throwable $exception) {
            $this->logFailure('Unable to resolve the empty source-cache result.', $spec, $exception);

            return [];
        }
    }

    private function supportsAsyncDispatch(): bool
    {
        return $this->operations->supportsAsyncDispatch();
    }

    private function dispatchRevalidation(
        SourceFetchSpec $spec,
        CacheProfile $profile,
        bool $ignoreFailureMarker = false,
    ): bool {
        if (!$ignoreFailureMarker && $this->hasFailureMarker($spec)) {
            return false;
        }

        try {
            // Dispatchable's PendingDispatch acquires Laravel's ShouldBeUnique
            // lock. Calling Bus\Dispatcher::dispatch() directly would bypass
            // that acquisition path.
            RevalidateSourceCache::dispatch($spec, $profile);

            return true;
        } catch (Throwable $exception) {
            $this->logFailure('Unable to dispatch source-cache revalidation.', $spec, $exception);

            return false;
        }
    }

    private function hasFailureMarker(SourceFetchSpec $spec): bool
    {
        $key = $this->failureMarkerKey($spec);
        $marker = $this->cache->get($key);

        if (!is_array($marker)
            || ($marker['v'] ?? null) !== self::FAILURE_MARKER_VERSION
            || !is_int($marker['failed_until'] ?? null)) {
            return false;
        }

        if ($marker['failed_until'] <= time()) {
            $this->cache->forget($key);

            return false;
        }

        return true;
    }

    private function markFailure(SourceFetchSpec $spec, CacheProfile $profile, Throwable $exception): void
    {
        $ttl = $profile->failureMarkerTtlSeconds();
        $this->cache->put($this->failureMarkerKey($spec), [
            'v' => self::FAILURE_MARKER_VERSION,
            'failed_until' => time() + $ttl,
        ], $ttl);

        $this->logFailure('Source-cache fetch failed.', $spec, $exception);
    }

    private function failureMarkerKey(SourceFetchSpec $spec): string
    {
        return $spec->cacheKey().':failure:v1';
    }

    private function logFailure(string $message, SourceFetchSpec $spec, Throwable $exception): void
    {
        $this->logger?->warning($message, [
            'source' => $spec->sourceKey,
            'operation' => $spec->operation,
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
        ]);
    }
}
