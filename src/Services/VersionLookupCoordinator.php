<?php

namespace Boy132\MinecraftModrinth\Services;

use App\Models\Server;
use Boy132\MinecraftModrinth\Contracts\BatchLatestVersionSourceInterface;
use Boy132\MinecraftModrinth\Enums\ModrinthProjectType;
use Boy132\MinecraftModrinth\Support\LatestVersionLookupRequest;
use Boy132\MinecraftModrinth\Support\LatestVersionLookupResult;
use Boy132\MinecraftModrinth\Support\ProjectSourceRegistry;
use Throwable;

class VersionLookupCoordinator
{
    public function __construct(
        protected ProjectSourceRegistry $registry,
    ) {}

    /**
     * @param array<int, array<string, mixed>> $installedMods
     */
    public function lookupInstalled(
        array $installedMods,
        Server $server,
        ModrinthProjectType $type,
    ): LatestVersionLookupResult {
        $requests = [];

        foreach ($installedMods as $installedMod) {
            $request = LatestVersionLookupRequest::fromInstalledMod($installedMod);

            if ($request !== null) {
                $requests[] = $request;
            }
        }

        return $this->lookup($requests, $server, $type);
    }

    /**
     * @param array<int, LatestVersionLookupRequest> $requests
     */
    public function lookup(
        array $requests,
        Server $server,
        ModrinthProjectType $type,
    ): LatestVersionLookupResult {
        $bySource = [];

        foreach ($requests as $request) {
            if ($request instanceof LatestVersionLookupRequest) {
                $bySource[$request->source][] = $request;
            }
        }

        $result = LatestVersionLookupResult::empty();

        foreach ($bySource as $sourceKey => $sourceRequests) {
            $source = $this->registry->getByValue($sourceKey);

            if (!$source instanceof BatchLatestVersionSourceInterface || !$source->isConfigured()) {
                $result = $result->merge(LatestVersionLookupResult::failed(
                    $sourceRequests,
                    "Latest-version lookup is unavailable for source [$sourceKey]",
                ));

                continue;
            }

            try {
                $result = $result->merge($source->lookupLatestVersions($sourceRequests, $server, $type));
            } catch (Throwable $exception) {
                report($exception);
                $result = $result->merge(LatestVersionLookupResult::failed(
                    $sourceRequests,
                    "Latest-version lookup failed for source [$sourceKey]",
                ));
            }
        }

        return $result;
    }
}
