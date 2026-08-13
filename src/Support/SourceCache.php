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
     * Fetch a value that is both authoritative and fresh.
     *
     * This is intentionally narrower than swrRequired(): Installed metadata
     * warming may write a negative per-project entry for an id missing from a
     * successful batch response, so a stale batch result is not sufficient
     * evidence that the project is still absent. Fresh entries retain the
     * ordinary cache fast path; stale entries are revalidated synchronously.
     */
    public function swrRequiredFresh(SourceFetchSpec $spec, CacheProfile $profile): mixed
    {
        $entry = $this->readEntry($spec);

        if ($entry !== null && $entry['fresh_until'] > time()) {
            return $entry['data'];
        }

        if ($this->hasFailureMarker($spec)) {
            throw new RuntimeException("Source [{$spec->sourceKey}] operation [{$spec->operation}] is temporarily unavailable.");
        }

        try {
            return $this->fetchAndStore($spec, $profile, $profile->backgroundTimeoutSeconds());
        } catch (Throwable $exception) {
            $this->markFailure($spec, $profile, $exception);

            // A stale entry remains available to ordinary render-path SWR
            // reads. Do not queue another attempt from this already-queued
            // metadata warming job.
            if ($entry === null && $this->supportsAsyncDispatch()) {
                $this->dispatchRevalidation($spec, $profile, ignoreFailureMarker: true);
            }

            throw $exception;
        }
    }

    /**
     * Execute a queued refresh. Failures intentionally leave any stale entry
     * untouched and are absorbed after recording a short-lived marker.
     */
    public function revalidate(SourceFetchSpec $spec, CacheProfile $profile): bool
    {
        try {
            $this->fetchAndStore($spec, $profile, $profile->backgroundTimeoutSeconds());

            return true;
        } catch (Throwable $exception) {
            $this->markFailure($spec, $profile, $exception);

            return false;
        }
    }

    /**
     * Return a cached value without fetching or dispatching.
     *
     * The boolean distinguishes a cached null from a miss and is used by
     * progressive-enrichment render paths.
     *
     * @return array{hit: bool, data: mixed, fresh: bool, retry_delayed: bool}
     */
    public function peek(SourceFetchSpec $spec): array
    {
        $entry = $this->readEntry($spec);

        return [
            'hit' => $entry !== null,
            'data' => $entry['data'] ?? null,
            'fresh' => $entry !== null && $entry['fresh_until'] > time(),
            // A failure marker means the last fetch failed. It is deliberately
            // distinct from a cached null: callers must not treat a temporary
            // retry cooldown as proof that the project was removed upstream.
            'retry_delayed' => $entry === null && $this->hasFailureMarker($spec),
        ];
    }

    /**
     * Batched read-only counterpart to peek(). Cache stores such as Redis map
     * this to one MGET, avoiding one network round trip per Installed row.
     * Retry-cooldown markers are read in that same MGET so a cold entry whose
     * last refresh failed can remain distinguishable from a real cache miss.
     *
     * @param array<string, SourceFetchSpec> $specs
     * @return array<string, array{hit: bool, data: mixed, fresh: bool, retry_delayed: bool}>
     */
    public function peekMany(array $specs): array
    {
        if ($specs === []) {
            return [];
        }

        $keys = [];
        $failureMarkerKeys = [];
        foreach ($specs as $key => $spec) {
            $keys[$key] = $spec->cacheKey();
            $failureMarkerKeys[$key] = $this->failureMarkerKey($spec);
        }

        $payloads = $this->cache->many(array_values(array_unique([
            ...array_values($keys),
            ...array_values($failureMarkerKeys),
        ])));
        $now = time();
        $results = [];

        foreach ($keys as $key => $cacheKey) {
            $entry = $this->entryFromPayload($payloads[$cacheKey] ?? null);
            $results[$key] = [
                'hit' => $entry !== null,
                'data' => $entry['data'] ?? null,
                'fresh' => $entry !== null && $entry['fresh_until'] > $now,
                'retry_delayed' => $entry === null && $this->hasActiveFailureMarker(
                    $payloads[$failureMarkerKeys[$key]] ?? null,
                    $now,
                ),
            ];
        }

        return $results;
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

    /**
     * Non-blocking counterpart to swr(): never performs an inline fetch.
     *
     * A fresh hit returns immediately. A stale hit also returns
     * immediately, and queues a background revalidation exactly like
     * swr() does. A miss queues a background fetch (when the queue
     * supports it - see revalidateAsync()) and returns the operation's
     * empty result instead of waiting for one, so a render path can show
     * a placeholder rather than block on a cold cache. Callers that need
     * to tell "genuinely empty" apart from "not checked yet" should use
     * the pending flag rather than inspecting the returned data.
     *
     * @return array{data: mixed, pending: bool, retry_delayed: bool}
     */
    public function swrDeferred(SourceFetchSpec $spec, CacheProfile $profile): array
    {
        $peeked = $this->peek($spec);

        if ($peeked['hit']) {
            if (!$peeked['fresh']) {
                $this->revalidateAsync($spec, $profile);
            }

            return ['data' => $peeked['data'], 'pending' => false, 'retry_delayed' => false];
        }

        // A failure marker (or a sync/null queue) means no background fetch
        // was actually scheduled. Reporting this as pending keeps callers
        // polling forever for a value that cannot change until the marker
        // expires or queue configuration is fixed.
        $pending = $this->revalidateAsync($spec, $profile);

        return [
            'data' => $this->emptyResult($spec),
            'pending' => $pending,
            'retry_delayed' => $peeked['retry_delayed'],
        ];
    }

    /** @return array{v: int, data: mixed, fresh_until: int}|null */
    private function readEntry(SourceFetchSpec $spec): ?array
    {
        return $this->entryFromPayload($this->cache->get($spec->cacheKey()));
    }

    /** @return array{v: int, data: mixed, fresh_until: int}|null */
    private function entryFromPayload(mixed $payload): ?array
    {
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
        $this->storeEntry($spec, $data, $profile);

        return $data;
    }

    /**
     * Write already-fetched data under the same fresh/stale semantics
     * fetchAndStore() applies, without performing a fetch. Used to prime
     * many per-entity cache entries at once from a single batched upstream
     * call (see Jobs\WarmProjectMetadata) - the batch call itself still
     * goes through the executor directly rather than through swr(), so it
     * is never cached as one combined entry keyed by the whole batch.
     *
     * @param array<int, array{spec: SourceFetchSpec, data: mixed}> $entries
     */
    public function primeMany(array $entries, CacheProfile $profile): void
    {
        $staleTtl = $profile->staleTtlSeconds();

        if ($staleTtl === null) {
            foreach ($entries as $entry) {
                $this->storeEntry($entry['spec'], $entry['data'], $profile);
            }

            return;
        }

        $payloads = [];
        $failureMarkers = [];
        $freshUntil = time() + $profile->freshTtlSeconds();

        foreach ($entries as $entry) {
            $cacheKey = $entry['spec']->cacheKey();
            $payloads[$cacheKey] = [
                'v' => self::SCHEMA_VERSION,
                'data' => $entry['data'],
                'fresh_until' => $freshUntil,
            ];
            $failureMarkers[$this->failureMarkerKey($entry['spec'])] = true;
        }

        if ($payloads === []) {
            return;
        }

        // Laravel's Redis store writes putMany() as one MULTI/EXEC
        // transaction. Project metadata warming therefore avoids one network
        // write per row while retaining each entry's existing TTL semantics.
        $this->cache->putMany($payloads, $staleTtl);

        foreach (array_keys($failureMarkers) as $failureMarker) {
            $this->cache->forget($failureMarker);
        }
    }

    /**
     * Record a short retry cooldown for several entity entries without
     * persisting an empty/negative result. This is used when one authoritative
     * batch request fails before it can prime its individual project entries:
     * the batch cache key has a failure marker, but the next render reads the
     * per-project keys.
     *
     * @param array<int, SourceFetchSpec> $specs
     */
    public function markRetryDelayedMany(array $specs, CacheProfile $profile): void
    {
        if ($specs === []) {
            return;
        }

        $ttl = $profile->failureMarkerTtlSeconds();
        $failedUntil = time() + $ttl;
        $markers = [];

        foreach ($specs as $spec) {
            $markers[$this->failureMarkerKey($spec)] = [
                'v' => self::FAILURE_MARKER_VERSION,
                'failed_until' => $failedUntil,
            ];
        }

        if ($markers !== []) {
            $this->cache->putMany($markers, $ttl);
        }
    }

    private function storeEntry(SourceFetchSpec $spec, mixed $data, CacheProfile $profile): void
    {
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

        if (!$this->hasActiveFailureMarker($marker, time())) {
            // An expired (but not yet evicted) marker is harmless, but remove
            // it here because this path is already doing a single-key read.
            if (is_array($marker)
                && ($marker['v'] ?? null) === self::FAILURE_MARKER_VERSION
                && is_int($marker['failed_until'] ?? null)) {
                $this->cache->forget($key);
            }

            return false;
        }

        return true;
    }

    private function hasActiveFailureMarker(mixed $marker, int $now): bool
    {
        return is_array($marker)
            && ($marker['v'] ?? null) === self::FAILURE_MARKER_VERSION
            && is_int($marker['failed_until'] ?? null)
            && $marker['failed_until'] > $now;
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
