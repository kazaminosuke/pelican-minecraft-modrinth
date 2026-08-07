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

    /**
     * Non-blocking counterpart to lookupLatestVersions(): a fresh or stale
     * cache hit is returned normally, but a cache miss is never fetched
     * inline - it queues a background refresh and the corresponding
     * request keys come back in the result's pendingKeys() instead of
     * unresolvedKeys(), so a render path can tell "still checking" apart
     * from "confirmed no match".
     *
     * @param array<int, LatestVersionLookupRequest> $requests
     */
    public function peekLatestVersions(
        array $requests,
        Server $server,
        ProjectType $type,
    ): LatestVersionLookupResult;
}
