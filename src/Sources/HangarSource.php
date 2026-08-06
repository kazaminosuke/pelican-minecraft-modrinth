<?php

namespace Kazaminosuke\ModManager\Sources;

use App\Models\Server;
use Exception;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Kazaminosuke\ModManager\Contracts\BatchLatestVersionSourceInterface;
use Kazaminosuke\ModManager\Contracts\ProjectSourceInterface;
use Kazaminosuke\ModManager\Enums\MinecraftLoader;
use Kazaminosuke\ModManager\Enums\ProjectSourceKey;
use Kazaminosuke\ModManager\Enums\ProjectType;
use Kazaminosuke\ModManager\Support\CacheVersion;
use Kazaminosuke\ModManager\Support\LatestVersionLookupRequest;
use Kazaminosuke\ModManager\Support\LatestVersionLookupResult;
use Kazaminosuke\ModManager\Support\MinecraftVersionResolver;
use Throwable;

class HangarSource implements BatchLatestVersionSourceInterface, ProjectSourceInterface
{
    protected const BASE_URL = 'https://hangar.papermc.io/api/v1';

    /** Hangar's version-listing endpoint caps `limit` at 25. */
    protected const PAGE_SIZE = 25;

    /**
     * Hangar's hash lookup only tells you which *project* matched, not which
     * version/file - this bounds how many recent-versions pages we scan (per
     * matched project) to resolve the exact file. Older files outside this
     * window won't be found; that's an API limitation, not a shortcut.
     */
    protected const HASH_SCAN_MAX_PAGES = 4;

    /**
     * A hash->version match is an immutable fact (a given file's bytes will
     * always resolve to the same Hangar file), so this cache is kept far
     * longer than the other API-response caches in this codebase.
     */
    protected const HASH_MATCH_CACHE_DAYS = 7;

    /** Hangar has no bulk endpoint, so bound the concurrent fallbacks. */
    protected const LATEST_VERSION_POOL_SIZE = 4;

    public function getKey(): ProjectSourceKey
    {
        return ProjectSourceKey::Hangar;
    }

    public function getLabel(): string
    {
        return 'Hangar';
    }

    public function requiresApiKey(): bool
    {
        return false;
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function supportsProjectType(ProjectType $type): bool
    {
        return $type === ProjectType::Plugin;
    }

    public function supportsSearch(): bool
    {
        return true;
    }

    public function supportsHashLookup(): bool
    {
        return true;
    }

    public function getHashAlgorithm(): ?string
    {
        return 'sha256';
    }

    public function supportsDirectIdentifier(): bool
    {
        return true;
    }

    /** @return array{hits: array<int, array<string, mixed>>, total_hits: int} */
    public function search(Server $server, ProjectType $type, int $page = 1, ?string $search = null, array $filters = []): array
    {
        if ($type !== ProjectType::Plugin) {
            return ['hits' => [], 'total_hits' => 0];
        }

        $platform = $this->platformFor($server);

        if ($platform === null) {
            return ['hits' => [], 'total_hits' => 0];
        }

        $params = [
            'platform' => $platform,
            'version' => MinecraftVersionResolver::resolve($server),
            'limit' => self::PAGE_SIZE,
            'offset' => ($page - 1) * self::PAGE_SIZE,
            // Hangar's ProjectSortingStrategy enum (confirmed against its
            // official OpenAPI spec): each value already bakes in a fixed
            // direction (e.g. "downloads" is always most-downloaded-first),
            // same as Modrinth's index and CurseForge's sortField - no
            // separate ascending/descending option. "stars" is Hangar's own
            // community-rating signal, the closest match to the catalog
            // dropdown's "popularity" preset.
            'sort' => match ($filters['sort'] ?? 'downloads') {
                'updated' => 'updated',
                'popularity' => 'stars',
                default => 'downloads',
            },
        ];

        if ($search) {
            $params['query'] = $search;
        }

        $cacheKey = 'hangar_search:'.md5(json_encode($params));

        $response = cache()->remember($cacheKey, now()->addMinutes(30), fn () => $this->getJson('/projects', $params));

        $hits = collect($response['result'] ?? [])
            ->filter(fn ($project) => is_array($project))
            ->map(fn (array $project) => $this->normalizeProject($project))
            ->values()
            ->all();

        return [
            'hits' => $hits,
            'total_hits' => (int) ($response['pagination']['count'] ?? count($hits)),
        ];
    }

    /** @return array<string, mixed>|null */
    public function getProject(string $projectId): ?array
    {
        return cache()->remember("hangar_project:$projectId", now()->addMinutes(30), function () use ($projectId) {
            $response = $this->getJson("/projects/$projectId");

            return isset($response['id']) ? $this->normalizeProject($response) : null;
        });
    }

    /**
     * Hangar has no bulk project-lookup endpoint, so this loops getProject()
     * per id; each call already degrades gracefully on its own.
     *
     * @param array<int, string> $projectIds
     * @return array<string, mixed>
     */
    public function getProjectsByIds(array $projectIds): array
    {
        $map = [];

        foreach (array_unique($projectIds) as $projectId) {
            $project = $this->getProject((string) $projectId);

            if ($project !== null) {
                $map[(string) $projectId] = $project;
            }
        }

        return $map;
    }

    /** @return array<int, mixed> */
    public function getVersions(string $projectId, Server $server, ProjectType $type): array
    {
        if ($type !== ProjectType::Plugin) {
            return [];
        }

        $platform = $this->platformFor($server);

        if ($platform === null) {
            return [];
        }

        $params = [
            'platform' => $platform,
            'platformVersion' => MinecraftVersionResolver::resolve($server),
            'limit' => self::PAGE_SIZE,
        ];

        $cacheKey = "hangar_versions:$projectId:".md5(json_encode($params));

        $response = cache()->remember($cacheKey, now()->addMinutes(30), fn () => $this->getJson("/projects/$projectId/versions", $params));

        return collect($response['result'] ?? [])
            ->filter(fn ($version) => is_array($version))
            ->map(fn (array $version) => $this->normalizeVersion($version, $platform))
            ->filter(fn ($version) => $version !== null)
            ->values()
            ->all();
    }

    /**
     * @param array<int, LatestVersionLookupRequest> $requests
     */
    public function lookupLatestVersions(
        array $requests,
        Server $server,
        ProjectType $type,
    ): LatestVersionLookupResult {
        $requests = array_values(array_filter(
            $requests,
            fn ($request): bool => $request instanceof LatestVersionLookupRequest,
        ));

        if ($requests === []) {
            return LatestVersionLookupResult::empty();
        }

        $platform = $this->platformFor($server);

        if ($type !== ProjectType::Plugin || $platform === null) {
            return new LatestVersionLookupResult(unresolvedKeys: array_map(
                fn (LatestVersionLookupRequest $request): string => $request->key(),
                $requests,
            ));
        }

        $params = [
            'platform' => $platform,
            'platformVersion' => MinecraftVersionResolver::resolve($server),
            'limit' => self::PAGE_SIZE,
        ];
        $requestsByProject = [];
        $versions = [];
        $unresolved = [];
        $failures = [];
        $pending = [];
        $cacheHits = 0;

        foreach ($requests as $request) {
            $requestsByProject[$request->projectId][] = $request;
        }

        foreach ($requestsByProject as $projectId => $projectRequests) {
            $cacheKey = 'hangar_latest_version:v1:'.$projectId.':'.md5(json_encode($params));
            $cached = cache()->get($cacheKey);

            if (is_array($cached)) {
                foreach ($projectRequests as $request) {
                    $versions[$request->key()] = $cached;
                }
                $cacheHits += count($projectRequests);

                continue;
            }

            $pending[$projectId] = $cacheKey;
        }

        $startedAt = (bool) config('pelican-minecraft-modrinth.debug_timing', false)
            ? microtime(true)
            : 0.0;
        $successfulProjects = 0;
        $requestCount = 0;

        foreach (array_chunk(array_keys($pending), self::LATEST_VERSION_POOL_SIZE) as $projectIds) {
            [$resolved, $chunkFailures] = $this->fetchLatestVersionChunk($projectIds, $params, $platform);
            $requestCount += count($projectIds);

            foreach ($projectIds as $projectId) {
                if (isset($chunkFailures[$projectId])) {
                    foreach ($requestsByProject[$projectId] as $request) {
                        $failures[$request->key()] = $chunkFailures[$projectId];
                    }

                    continue;
                }

                if (!isset($resolved[$projectId])) {
                    foreach ($requestsByProject[$projectId] as $request) {
                        $unresolved[] = $request->key();
                    }

                    continue;
                }

                cache()->put($pending[$projectId], $resolved[$projectId], now()->addMinutes(30));
                $successfulProjects++;

                foreach ($requestsByProject[$projectId] as $request) {
                    $versions[$request->key()] = $resolved[$projectId];
                }
            }
        }

        if ($pending !== []) {
            $this->logLatestVersionsTiming(
                $startedAt,
                count($pending),
                $successfulProjects,
                $requestCount,
                $cacheHits,
                count($failures),
            );
        }

        return new LatestVersionLookupResult(
            versionsByKey: $versions,
            unresolvedKeys: $unresolved,
            failuresByKey: $failures,
        );
    }

    /**
     * @param array<string, string> $hashesByFilename [filename => sha256hash]
     * @return array<string, mixed> [sha256hash => normalized version data]
     */
    public function findVersionsByHash(array $hashesByFilename): array
    {
        if (empty($hashesByFilename)) {
            return [];
        }

        $results = [];

        foreach (array_unique(array_values($hashesByFilename)) as $hash) {
            $hash = (string) $hash;

            if (!preg_match('/^[a-f0-9]{64}$/i', $hash)) {
                continue;
            }

            $project = $this->getJson("/versions/hash/$hash");

            if (!isset($project['id'])) {
                continue;
            }

            $entry = $this->findVersionEntryByHash((string) $project['id'], strtolower($hash));

            if ($entry !== null) {
                $results[strtolower($hash)] = $entry;
            }
        }

        return $results;
    }

    /** @return array<string, mixed>|null */
    public function resolveProjectByIdentifier(string $identifier): ?array
    {
        return $this->getProject($identifier);
    }

    /**
     * Scans a bounded window of a project's most recent versions (across all
     * platforms) for the file matching the given sha256 hash, since Hangar's
     * hash endpoint only identifies the parent project. This is the expensive
     * part of Hangar hash matching, so a successful result is cached by hash
     * (see HASH_MATCH_CACHE_TTL) - the hash is the cache key, so if a file's
     * content ever changes its hash changes too and the old entry is simply
     * never looked up again, with no explicit invalidation needed on its own.
     * The plugin settings "clear cache" action has no per-file granularity to
     * target though, so CacheVersion::hangarHash() is also folded into the
     * key - bumping it is the only way that action can invalidate this cache
     * as a whole. Only successful matches are cached; a miss isn't, since a
     * project could be published to Hangar after this file was last scanned.
     */
    protected function findVersionEntryByHash(string $projectId, string $hash): ?array
    {
        $cacheKey = 'hangar_hash_match:v2:'.CacheVersion::hangarHash().":$hash";
        $cached = cache()->get($cacheKey);

        if (is_array($cached)) {
            return $cached;
        }

        $entry = null;

        for ($page = 0; $page < self::HASH_SCAN_MAX_PAGES; $page++) {
            $response = $this->getJson("/projects/$projectId/versions", [
                'limit' => self::PAGE_SIZE,
                'offset' => $page * self::PAGE_SIZE,
            ]);

            $versions = $response['result'] ?? [];

            if (!is_array($versions) || empty($versions)) {
                break;
            }

            foreach ($versions as $version) {
                if (!is_array($version)) {
                    continue;
                }

                foreach (($version['downloads'] ?? []) as $platform => $download) {
                    $fileHash = strtolower((string) ($download['fileInfo']['sha256Hash'] ?? ''));

                    if ($fileHash !== '' && $fileHash === $hash) {
                        $entry = $this->normalizeVersion($version, $platform);

                        if ($entry !== null) {
                            $entry['project_id'] = $projectId;
                        }

                        break 3;
                    }
                }
            }

            if (count($versions) < self::PAGE_SIZE) {
                break;
            }
        }

        if ($entry !== null) {
            cache()->put($cacheKey, $entry, now()->addDays(self::HASH_MATCH_CACHE_DAYS));
        }

        return $entry;
    }

    /**
     * Maps the source-agnostic MinecraftLoader to a Hangar Platform. Hangar only
     * covers the PaperMC ecosystem (PAPER/WATERFALL/VELOCITY); Paper-API-compatible
     * forks (Purpur, Folia) are treated as PAPER. Spigot/Bukkit/Bungeecord and
     * other loaders have no Hangar equivalent.
     */
    protected function platformFor(Server $server): ?string
    {
        return match (MinecraftLoader::fromServer($server)) {
            MinecraftLoader::Paper, MinecraftLoader::Purpur, MinecraftLoader::Folia => 'PAPER',
            MinecraftLoader::Waterfall => 'WATERFALL',
            MinecraftLoader::Velocity => 'VELOCITY',
            default => null,
        };
    }

    /** @param array<string, mixed> $project */
    protected function normalizeProject(array $project): array
    {
        $namespace = $project['namespace'] ?? [];

        return [
            'project_id' => (string) ($project['id'] ?? ''),
            'slug' => $namespace['slug'] ?? '',
            'title' => $project['name'] ?? '',
            'description' => $project['description'] ?? '',
            'icon_url' => $project['avatarUrl'] ?? null,
            'author' => $namespace['owner'] ?? null,
            'downloads' => (int) ($project['stats']['downloads'] ?? 0),
            'date_modified' => $project['lastUpdated'] ?? null,
            'project_type' => ProjectType::Plugin->value,
        ];
    }

    /**
     * @param array<string, mixed> $version
     * @return array<string, mixed>|null  null when this version has no file for the given platform
     */
    protected function normalizeVersion(array $version, string $platform): ?array
    {
        $download = $version['downloads'][$platform] ?? null;

        if (!is_array($download)) {
            return null;
        }

        $fileInfo = $download['fileInfo'] ?? [];
        $url = $download['downloadUrl'] ?? $download['externalUrl'] ?? null;

        // Channels are free-text and admin-configurable per project (e.g. "Release",
        // "Beta", "Snapshot", "Dev Build"), so this is a best-effort heuristic rather
        // than an exact enum match like Modrinth/CurseForge have.
        $channelName = strtolower($version['channel']['name'] ?? '');
        $versionType = match (true) {
            str_contains($channelName, 'beta') => 'beta',
            str_contains($channelName, 'alpha'), str_contains($channelName, 'snapshot'), str_contains($channelName, 'dev') => 'alpha',
            default => 'release',
        };

        $hashes = [];
        if (!empty($fileInfo['sha256Hash'])) {
            $hashes['sha256'] = $fileInfo['sha256Hash'];
        }

        return [
            'id' => (string) ($version['id'] ?? ''),
            'version_number' => $version['name'] ?? '',
            'version_type' => $versionType,
            'downloads' => (int) ($version['stats']['totalDownloads'] ?? 0),
            'date_published' => $version['createdAt'] ?? null,
            'changelog' => $version['description'] ?? null,
            'featured' => false,
            'files' => [
                [
                    'primary' => true,
                    'filename' => $fileInfo['name'] ?? '',
                    'url' => $url,
                    'hashes' => $hashes,
                ],
            ],
        ];
    }

    /**
     * @param array<int, string> $projectIds
     * @param array<string, int|string> $params
     * @return array{0: array<string, array<string, mixed>>, 1: array<string, string>}
     */
    protected function fetchLatestVersionChunk(array $projectIds, array $params, string $platform): array
    {
        $aliases = [];

        try {
            $responses = Http::pool(function (Pool $pool) use ($projectIds, $params, &$aliases) {
                $poolRequests = [];

                foreach ($projectIds as $index => $projectId) {
                    $alias = "hangar_$index";
                    $aliases[$alias] = $projectId;
                    $poolRequests[] = $pool->as($alias)
                        ->asJson()
                        ->timeout(10)
                        ->connectTimeout(5)
                        ->get(self::BASE_URL."/projects/$projectId/versions", $params);
                }

                return $poolRequests;
            });
        } catch (Throwable $exception) {
            report($exception);

            return [[], array_fill_keys($projectIds, $exception->getMessage())];
        }

        $resolved = [];
        $failures = [];

        foreach ($aliases as $alias => $projectId) {
            try {
                $response = $responses[$alias] ?? null;

                if (!$response instanceof Response || !$response->successful()) {
                    throw new Exception("Hangar versions lookup failed for project $projectId");
                }

                $payload = $response->json();

                if (!is_array($payload)) {
                    throw new Exception("Invalid Hangar versions response for project $projectId");
                }

                $version = $this->latestNormalizedVersion($payload, $platform);
                if ($version !== null) {
                    $resolved[$projectId] = $version;
                }
            } catch (Throwable $exception) {
                report($exception);
                $failures[$projectId] = $exception->getMessage();
            }
        }

        return [$resolved, $failures];
    }

    /**
     * @param array<string, mixed> $response
     * @return array<string, mixed>|null
     */
    protected function latestNormalizedVersion(array $response, string $platform): ?array
    {
        foreach ($response['result'] ?? [] as $version) {
            if (!is_array($version)) {
                continue;
            }

            $normalized = $this->normalizeVersion($version, $platform);
            if ($normalized !== null) {
                return $normalized;
            }
        }

        return null;
    }

    protected function logLatestVersionsTiming(
        float $startedAt,
        int $requestedProjectCount,
        int $returnedProjectCount,
        int $requestCount,
        int $cacheHitCount,
        int $failureCount,
    ): void {
        if (!(bool) config('pelican-minecraft-modrinth.debug_timing', false)) {
            return;
        }

        logger()->info('Mod manager timing', [
            'stage' => 'hangar_versions_parallel_request',
            'request_id' => request()->attributes->get('mmr_timing_request_id'),
            'endpoint' => '/projects/{project}/versions',
            'started_after_ms' => $this->getModManagerTimingElapsedMs($startedAt),
            'finished_after_ms' => $this->getModManagerTimingElapsedMs(),
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'requested_project_count' => $requestedProjectCount,
            'returned_project_count' => $returnedProjectCount,
            'request_count' => $requestCount,
            'pool_size' => self::LATEST_VERSION_POOL_SIZE,
            'cache_hit_count' => $cacheHitCount,
            'failure_count' => $failureCount,
        ]);
    }

    protected function getModManagerTimingElapsedMs(?float $timestamp = null): ?int
    {
        if (!(bool) config('pelican-minecraft-modrinth.debug_timing', false)) {
            return null;
        }

        $requestStartedAt = request()->attributes->get('mmr_timing_started_at');

        if (!is_float($requestStartedAt)) {
            return null;
        }

        return (int) round((($timestamp ?? microtime(true)) - $requestStartedAt) * 1000);
    }

    /** @param array<string, mixed> $query */
    protected function getJson(string $path, array $query = []): array
    {
        try {
            $response = Http::asJson()
                ->timeout(10)
                ->connectTimeout(5)
                ->throw()
                ->get(self::BASE_URL.$path, $query)
                ->json();

            return is_array($response) ? $response : [];
        } catch (Exception $exception) {
            report($exception);

            return [];
        }
    }
}
