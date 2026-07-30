<?php

namespace Boy132\MinecraftModrinth\Contracts;

use App\Models\Server;
use Boy132\MinecraftModrinth\Enums\ModrinthProjectType;
use Boy132\MinecraftModrinth\Support\LatestVersionLookupRequest;
use Boy132\MinecraftModrinth\Support\LatestVersionLookupResult;

/**
 * Optional source capability for resolving the latest compatible version of
 * several installed projects without loading each project's complete version
 * history.
 *
 * `ProjectSourceInterface::getVersions()` remains the full-history API used by
 * the versions modal. Implementations of this contract must use separate cache
 * entries so a latest-only response can never truncate that history.
 */
interface BatchLatestVersionSourceInterface
{
    /**
     * @param array<int, LatestVersionLookupRequest> $requests
     */
    public function lookupLatestVersions(
        array $requests,
        Server $server,
        ModrinthProjectType $type,
    ): LatestVersionLookupResult;
}
