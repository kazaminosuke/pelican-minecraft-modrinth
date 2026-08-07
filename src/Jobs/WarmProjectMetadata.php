<?php

namespace Kazaminosuke\ModManager\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Kazaminosuke\ModManager\Support\ProjectSourceRegistry;
use Throwable;

/**
 * Fills many projects' cache entries for one source from a single upstream
 * call, instead of each project's own SourceCache miss queuing its own
 * individual revalidation job (see ProjectSourceRegistry::peekInstalled(),
 * which collects a render pass's misses and dispatches this once per
 * source rather than once per project).
 *
 * getProjectsByIds() already fetches in bulk where the source actually has
 * a bulk endpoint (Modrinth, CurseForge) and loops individually where it
 * doesn't (Hangar, GitHub Releases) - either way, this reduces N queued
 * jobs down to one.
 *
 * Deliberately NOT throttled by WarmRequestThrottle: unlike
 * WarmCatalogSearch/WarmCatalogCacheCommand's speculative, nobody-may-be-
 * watching warming, this only ever runs because a user is actively
 * viewing their Installed tab right now (see peekInstalled()) - exactly
 * the "user-triggered" traffic the design calls out as staying
 * unthrottled, and the same treatment Stage 4's original per-project
 * dispatch already gave this work before this batching existed.
 */
final class WarmProjectMetadata implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    /**
     * Short: this exact set of misses is only meaningful until the next
     * Installed-tab render collects a (likely different, as items resolve)
     * set. A stale duplicate-suppression window here would just delay a
     * legitimately new batch.
     */
    public int $uniqueFor = 120;

    /** @param array<int, string> $projectIds */
    public function __construct(
        public readonly string $sourceKey,
        public readonly array $projectIds,
    ) {}

    public function uniqueId(): string
    {
        $ids = $this->projectIds;
        sort($ids);

        return "warm_project_metadata:{$this->sourceKey}:".hash('sha256', implode(',', $ids));
    }

    public function handle(ProjectSourceRegistry $registry): void
    {
        if ($this->projectIds === []) {
            return;
        }

        $source = $registry->getByValue($this->sourceKey);

        if (!$source) {
            return;
        }

        try {
            $map = $source->getProjectsByIds($this->projectIds);
        } catch (Throwable $exception) {
            report($exception);

            return;
        }

        if ($map !== []) {
            $source->primeProjects($map);
        }
    }
}
