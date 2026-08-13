<?php

namespace Kazaminosuke\ModManager\Contracts;

/**
 * Optional batched cache-read capability for Installed-tab enrichment.
 *
 * Implementations must not fetch upstream data or dispatch jobs. The returned
 * pending flag has the same meaning as ProjectSourceInterface::peekProject()
 * with dispatchOnMiss=false. retry_delayed identifies a short SourceCache
 * failure cooldown, which is neither a pending warm nor a definitive negative
 * cache hit.
 */
interface ProjectMetadataPeekManyInterface
{
    /**
     * @param array<int, string> $projectIds
     * @return array<string, array{data: array<string, mixed>|null, pending: bool, retry_delayed?: bool}>
     */
    public function peekProjects(array $projectIds): array;
}
