<?php

namespace Kazaminosuke\ModManager\Contracts;

/**
 * A source whose project-metadata batch endpoint can confirm that a requested
 * project is absent. The returned data is fresh, rather than a stale cache
 * fallback, so callers may safely prime a per-project negative cache entry.
 *
 * Hangar and GitHub deliberately do not implement this contract: their
 * per-project fallback lookups cannot distinguish every not-found response
 * from a transient upstream failure with the same certainty.
 */
interface AuthoritativeBatchProjectSourceInterface
{
    /**
     * @param array<int, string> $projectIds
     * @return array<string, mixed> [projectId => normalized project data]
     */
    public function getProjectsByIdsForMetadataWarm(array $projectIds): array;

    /**
     * Mark the normal per-project metadata entries for a short retry delay
     * after an authoritative batch request failed. This must not negative-cache
     * the ids: the failure says nothing about whether they still exist.
     *
     * @param array<int, string> $projectIds
     */
    public function deferProjectMetadataRetries(array $projectIds): void;
}
