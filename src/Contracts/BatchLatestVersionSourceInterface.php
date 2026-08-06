<?php

namespace Kazaminosuke\ModManager\Contracts;

use App\Models\Server;
use Kazaminosuke\ModManager\Enums\ProjectType;
use Kazaminosuke\ModManager\Support\LatestVersionLookupRequest;
use Kazaminosuke\ModManager\Support\LatestVersionLookupResult;

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
        ProjectType $type,
    ): LatestVersionLookupResult;
}
