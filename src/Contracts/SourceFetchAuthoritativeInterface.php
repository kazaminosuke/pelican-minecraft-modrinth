<?php

namespace Kazaminosuke\ModManager\Contracts;

/**
 * Source lookups used by authoritative background operations such as an
 * installed-file scan.
 *
 * Unlike render-path SWR reads, a cold-cache upstream failure must propagate
 * so the caller does not persist "no match" as if it were a valid response.
 * Fresh or stale cached data may still be returned.
 */
interface SourceFetchAuthoritativeInterface
{
    /**
     * @param array<int, string> $projectIds
     * @return array<string, mixed>
     */
    public function getProjectsByIdsAuthoritatively(array $projectIds): array;

    /**
     * @param array<string, string> $hashesByFilename
     * @return array<string, mixed>
     */
    public function findVersionsByHashAuthoritatively(array $hashesByFilename): array;
}
