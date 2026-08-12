<?php

namespace Kazaminosuke\ModManager\Contracts;

/**
 * Optional batched cache-read capability for Installed-tab enrichment.
 *
 * Implementations must not fetch upstream data or dispatch jobs. The returned
 * pending flag has the same meaning as ProjectSourceInterface::peekProject()
 * with dispatchOnMiss=false.
 */
interface ProjectMetadataPeekManyInterface
{
    /**
     * @param array<int, string> $projectIds
     * @return array<string, array{data: array<string, mixed>|null, pending: bool}>
     */
    public function peekProjects(array $projectIds): array;
}
