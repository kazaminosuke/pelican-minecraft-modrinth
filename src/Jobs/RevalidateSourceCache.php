<?php

namespace Kazaminosuke\ModManager\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Kazaminosuke\ModManager\Contracts\AuthoritativeBatchProjectSourceInterface;
use Kazaminosuke\ModManager\Support\CacheProfile;
use Kazaminosuke\ModManager\Support\ProjectSourceRegistry;
use Kazaminosuke\ModManager\Support\SourceCache;
use Kazaminosuke\ModManager\Support\SourceFetchSpec;

final class RevalidateSourceCache implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    public int $uniqueFor = 300;

    public function __construct(
        public readonly SourceFetchSpec $spec,
        public readonly CacheProfile $profile,
    ) {}

    public function uniqueId(): string
    {
        return $this->spec->cacheKey();
    }

    public function handle(SourceCache $cache, ProjectSourceRegistry $registry): void
    {
        if (!$cache->revalidate($this->spec, $this->profile)) {
            return;
        }

        // A cold Modrinth/CurseForge metadata batch can have failed while an
        // Installed page was open. Its retry marker stops the page poll, but
        // this successful background revalidation only fills the batch key.
        // Re-run the lightweight warmer so it primes the individual project
        // keys the visible table reads, without another upstream request.
        if ($this->profile !== CacheProfile::ProjectMetadata || $this->spec->operation !== 'projects') {
            return;
        }

        $projectIds = $this->normalizedProjectIds();
        $source = $registry->getByValue($this->spec->sourceKey);

        if ($projectIds === [] || !$source instanceof AuthoritativeBatchProjectSourceInterface) {
            return;
        }

        WarmProjectMetadata::dispatch($this->spec->sourceKey, $projectIds);
    }

    /** @return array<int, string> */
    private function normalizedProjectIds(): array
    {
        $projectIds = $this->spec->arguments['project_ids'] ?? [];

        if (!is_array($projectIds)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            array_map(static fn (mixed $projectId): string => trim((string) $projectId), $projectIds),
            static fn (string $projectId): bool => $projectId !== '',
        )));
    }
}
