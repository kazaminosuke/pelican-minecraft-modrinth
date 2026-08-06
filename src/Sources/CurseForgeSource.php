<?php

namespace Kazaminosuke\ModManager\Sources;

use App\Models\Server;
use Kazaminosuke\ModManager\Contracts\BatchLatestVersionSourceInterface;
use Kazaminosuke\ModManager\Contracts\ProjectSourceInterface;
use Kazaminosuke\ModManager\Enums\MinecraftLoader;
use Kazaminosuke\ModManager\Enums\ProjectType;
use Kazaminosuke\ModManager\Enums\ProjectSourceKey;
use Kazaminosuke\ModManager\Support\LatestVersionLookupRequest;
use Kazaminosuke\ModManager\Support\LatestVersionLookupResult;
use Kazaminosuke\ModManager\Support\MinecraftVersionResolver;
use Exception;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\Http;

class CurseForgeSource implements BatchLatestVersionSourceInterface, ProjectSourceInterface
{
    protected const BASE_URL = 'https://api.curseforge.com/v1';

    /** Minecraft's numeric CurseForge gameId. */
    protected const GAME_ID = 432;

    /** CurseForge classId for "Minecraft Mods". */
    protected const CLASS_ID_MOD = 6;

    /** CurseForge classId for "Bukkit Plugins". */
    protected const CLASS_ID_PLUGIN = 5;

    /**
     * The API documentation does not publish a maximum for POST /mods/files.
     * A live request with 105 entries (93 unique IDs) succeeds, but keep response sizes bounded.
     */
    protected const BULK_FILES_CHUNK_SIZE = 100;

    /**
     * CurseForge's ModsSearchSortField enum values used by the catalog sort
     * dropdown (1 Featured, 2 Popularity, 3 LastUpdated, 4 Name, 5 Author,
     * 6 TotalDownloads, 7 Category, 8 GameVersion, 9 EarlyAccess,
     * 10 FeaturedRelease, 11 ReleaseDate, 12 Rating).
     */
    protected const SORT_FIELD_TOTAL_DOWNLOADS = 6;

    protected const SORT_FIELD_LAST_UPDATED = 3;

    protected const SORT_FIELD_POPULARITY = 2;

    /** @var array<string, string> */
    protected array $lastWarmVersionFailures = [];

    public function getKey(): ProjectSourceKey
    {
        return ProjectSourceKey::CurseForge;
    }

    public function getLabel(): string
    {
        return 'CurseForge';
    }

    public function requiresApiKey(): bool
    {
        return true;
    }

    public function isConfigured(): bool
    {
        return filled($this->apiKey());
    }

    public function supportsProjectType(ProjectType $type): bool
    {
        return $this->classIdFor($type) !== null;
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
        return 'murmur2';
    }

    public function supportsDirectIdentifier(): bool
    {
        return true;
    }

    /** @return array{hits: array<int, array<string, mixed>>, total_hits: int} */
    public function search(Server $server, ProjectType $type, int $page = 1, ?string $search = null, array $filters = []): array
    {
        if (!$this->isConfigured()) {
            return ['hits' => [], 'total_hits' => 0];
        }

        $classId = $this->classIdFor($type);
        if (!$classId) {
            return ['hits' => [], 'total_hits' => 0];
        }

        $params = [
            'gameId' => self::GAME_ID,
            'classId' => $classId,
            'gameVersion' => MinecraftVersionResolver::resolve($server),
            'index' => ($page - 1) * 20,
            'pageSize' => 20,
            'sortField' => match ($filters['sort'] ?? 'downloads') {
                'updated' => self::SORT_FIELD_LAST_UPDATED,
                'popularity' => self::SORT_FIELD_POPULARITY,
                default => self::SORT_FIELD_TOTAL_DOWNLOADS,
            },
            'sortOrder' => 'desc',
        ];

        if ($classId === self::CLASS_ID_MOD) {
            $modLoaderType = $this->modLoaderTypeFor($server);
            if ($modLoaderType === null) {
                return ['hits' => [], 'total_hits' => 0];
            }
            $params['modLoaderType'] = $modLoaderType;
        }

        if (!empty($filters['category'])) {
            $params['categoryId'] = (int) $filters['category'];
        }
        if ($search) {
            $params['searchFilter'] = $search;
        }

        $cacheKey = 'curseforge_search:v2:'.md5(json_encode($params));
        $cached = cache()->get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $startedAt = microtime(true);
        $responseBytes = 0;

        try {
            $response = Http::asJson()
                ->withHeaders(['x-api-key' => $this->apiKey()])
                ->timeout(2)
                ->connectTimeout(1)
                ->throw()
                ->get(self::BASE_URL.'/mods/search', $params);
            $responseBytes = strlen($response->body());
            $payload = $response->json();

            if (!is_array($payload)) {
                throw new Exception('Invalid CurseForge search response');
            }

            $result = [
                'hits' => collect($payload['data'] ?? [])
                    ->filter(fn ($mod) => is_array($mod))
                    ->map(fn (array $mod) => $this->normalizeProject($mod, $type))
                    ->values()
                    ->all(),
                'total_hits' => (int) ($payload['pagination']['totalCount'] ?? 0),
            ];

            cache()->put($cacheKey, $result, now()->addMinutes(30));

            return $result;
        } catch (Exception $exception) {
            report($exception);

            return ['hits' => [], 'total_hits' => 0];
        } finally {
            if (config('app.debug')) {
                logger()->debug('Catalog search API timing', [
                    'source' => 'curseforge',
                    'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                    'response_bytes' => $responseBytes,
                ]);
            }
        }
    }

    /** @return array<string, mixed>|null */
    public function getProject(string $projectId): ?array
    {
        if (!$this->isConfigured()) {
            return null;
        }

        return cache()->remember("curseforge_mod:$projectId", now()->addMinutes(30), function () use ($projectId) {
            $response = $this->getJson("/mods/$projectId");
            $mod = $response['data'] ?? null;

            return is_array($mod) ? $this->normalizeProject($mod) : null;
        });
    }

    /**
     * @param array<int, string> $projectIds
     * @return array<string, mixed>
     *
     * @throws Exception
     */
    public function getProjectsByIds(array $projectIds): array
    {
        if (empty($projectIds) || !$this->isConfigured()) {
            return [];
        }

        $modIds = array_values(array_unique(array_map('intval', $projectIds)));

        try {
            $response = Http::asJson()
                ->withHeaders(['x-api-key' => $this->apiKey()])
                ->timeout(10)
                ->connectTimeout(5)
                ->throw()
                ->post(self::BASE_URL.'/mods', ['modIds' => $modIds])
                ->json();

            $mods = $response['data'] ?? [];

            if (!is_array($mods)) {
                return [];
            }

            $map = [];
            foreach ($mods as $mod) {
                if (is_array($mod) && isset($mod['id'])) {
                    $map[(string) $mod['id']] = $this->normalizeProject($mod);
                }
            }

            return $map;
        } catch (Exception $exception) {
            report($exception);

            throw new Exception('CurseForge projects lookup failed', previous: $exception);
        }
    }

    /** @return array<int, mixed> */
    public function getVersions(string $projectId, Server $server, ProjectType $type): array
    {
        $params = $this->getVersionRequestParams($server, $type);

        if ($params === null) {
            return [];
        }

        $cacheKey = "curseforge_files:$projectId:".md5(json_encode($params));
        $response = cache()->remember($cacheKey, now()->addMinutes(30), fn () => $this->getJson("/mods/$projectId/files", $params));

        return $this->normalizeVersions($response);
    }

    /**
     * Warm a latest-only per-project cache for update detection.
     *
     * CurseForge's bulk mods endpoint contains latestFiles plus
     * latestFilesIndexes. When those indexes can be resolved to full files for
     * the active Minecraft version and loader, one request replaces all
     * per-project lookups. Any project whose bulk data is incomplete falls
     * back to its existing files endpoint so update detection remains exact.
     * This cache is intentionally separate from the complete version history
     * consumed by getVersions() and the versions modal.
     *
     * @param array<int, string> $projectIds
     * @return array<string, array<int, mixed>>
     */
    public function warmVersions(array $projectIds, Server $server, ProjectType $type): array
    {
        $params = $this->getVersionRequestParams($server, $type);

        if ($params === null) {
            return [];
        }

        $this->lastWarmVersionFailures = [];
        $versionsByProjectId = [];
        $pending = [];

        foreach (array_values(array_unique($projectIds)) as $projectId) {
            $projectId = (string) $projectId;
            $cacheKey = "curseforge_latest:$projectId:".md5(json_encode($params));
            $cached = cache()->get($cacheKey);

            if (is_array($cached)) {
                $versionsByProjectId[$projectId] = $cached;

                continue;
            }

            $pending[$projectId] = $cacheKey;
        }

        if ($pending === []) {
            return $versionsByProjectId;
        }

        $bulkStartedAt = microtime(true);
        $bulkResponse = $this->getBulkMods(array_keys($pending));

        $this->logBulkVersionsTiming(
            $bulkStartedAt,
            count($pending),
            is_array($bulkResponse['data'] ?? null) ? count($bulkResponse['data']) : 0,
        );

        $bulkVersionsByProjectId = $this->extractBulkLatestVersions($bulkResponse, $params);

        foreach ($bulkVersionsByProjectId as $projectId => $payload) {
            if (!isset($pending[$projectId])) {
                continue;
            }

            $cacheKey = $pending[$projectId];
            $versions = $this->normalizeVersions($payload);
            if ($versions !== []) {
                cache()->put($cacheKey, $versions, now()->addMinutes(30));
                $versionsByProjectId[$projectId] = $versions;
            }
            unset($pending[$projectId]);
        }

        if ($pending === []) {
            return $versionsByProjectId;
        }

        try {
            $responses = Http::pool(function (Pool $pool) use ($pending, $params) {
                $requests = [];

                foreach (array_keys($pending) as $projectId) {
                    $requests[] = $pool->as((string) $projectId)
                        ->asJson()
                        ->withHeaders(['x-api-key' => $this->apiKey()])
                        ->timeout(10)
                        ->connectTimeout(5)
                        ->get(self::BASE_URL."/mods/$projectId/files", $params);
                }

                return $requests;
            });
        } catch (Exception $exception) {
            report($exception);
            $responses = [];
        }

        foreach ($pending as $projectId => $cacheKey) {
            try {
                $response = $responses[$projectId] ?? null;

                if ($response === null || !$response->successful()) {
                    throw new Exception("CurseForge versions lookup failed for project $projectId");
                }

                $payload = $response->json();

                if (!is_array($payload)) {
                    throw new Exception("Invalid CurseForge versions response for project $projectId");
                }

                $versions = $this->normalizeVersions($payload);
            } catch (Exception $exception) {
                report($exception);
                $this->lastWarmVersionFailures[(string) $projectId] = $exception->getMessage();

                continue;
            }

            // Do not negative-cache a transient API failure or an empty result.
            // A later request should be able to retry without waiting 30 minutes.
            if ($versions !== []) {
                cache()->put($cacheKey, $versions, now()->addMinutes(30));
                $versionsByProjectId[$projectId] = $versions;
            }
        }

        return $versionsByProjectId;
    }

    /**
     * @param array<int, LatestVersionLookupRequest> $requests
     */
    public function lookupLatestVersions(
        array $requests,
        Server $server,
        ProjectType $type,
    ): LatestVersionLookupResult {
        $startedAt = microtime(true);
        $validRequests = array_values(array_filter(
            $requests,
            fn ($request) => $request instanceof LatestVersionLookupRequest,
        ));

        if ($validRequests === []) {
            return LatestVersionLookupResult::empty();
        }

        $versionsByProjectId = $this->warmVersions(
            array_map(fn (LatestVersionLookupRequest $request) => $request->projectId, $validRequests),
            $server,
            $type,
        );

        $versionsByKey = [];
        $unresolvedKeys = [];
        $failuresByKey = [];

        foreach ($validRequests as $request) {
            $version = $versionsByProjectId[$request->projectId][0] ?? null;

            if (is_array($version)) {
                $versionsByKey[$request->key()] = $version;
            } elseif (isset($this->lastWarmVersionFailures[$request->projectId])) {
                $failuresByKey[$request->key()] = $this->lastWarmVersionFailures[$request->projectId];
            } else {
                $unresolvedKeys[] = $request->key();
            }
        }

        logger()->info('Mod manager timing', [
            'stage' => 'curseforge_latest_lookup_batch',
            'request_id' => request()->attributes->get('mmr_timing_request_id'),
            'started_after_ms' => $this->getModManagerTimingElapsedMs($startedAt),
            'finished_after_ms' => $this->getModManagerTimingElapsedMs(),
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'requested_project_count' => count($validRequests),
            'resolved_project_count' => count($versionsByKey),
            'unresolved_project_count' => count($unresolvedKeys),
            'failed_project_count' => count($failuresByKey),
        ]);

        return new LatestVersionLookupResult(
            versionsByKey: $versionsByKey,
            unresolvedKeys: $unresolvedKeys,
            failuresByKey: $failuresByKey,
        );
    }

    /**
     * @param array<int, string> $projectIds
     * @return array<string, mixed>
     */
    protected function getBulkMods(array $projectIds): array
    {
        try {
            $response = Http::asJson()
                ->withHeaders(['x-api-key' => $this->apiKey()])
                ->timeout(10)
                ->connectTimeout(5)
                ->throw()
                ->post(self::BASE_URL.'/mods', ['modIds' => array_map('intval', $projectIds)])
                ->json();

            return is_array($response) ? $response : [];
        } catch (Exception $exception) {
            report($exception);

            return [];
        }
    }

    /**
     * @param array<string, mixed> $response
     * @param array<string, int|string> $params
     * @return array<string, array<string, array<int, mixed>>>
     */
    protected function extractBulkLatestVersions(array $response, array $params): array
    {
        $fileIdsByProjectId = [];
        $filesById = [];

        foreach ($response['data'] ?? [] as $mod) {
            if (!is_array($mod) || !isset($mod['id']) || !is_array($mod['latestFiles'] ?? null) || !is_array($mod['latestFilesIndexes'] ?? null)) {
                continue;
            }

            $fileIds = [];
            foreach ($mod['latestFilesIndexes'] as $index) {
                if (!is_array($index) || ($index['gameVersion'] ?? null) !== $params['gameVersion']) {
                    continue;
                }

                if (isset($params['modLoaderType']) && (int) ($index['modLoader'] ?? -1) !== (int) $params['modLoaderType']) {
                    continue;
                }

                $fileIds[] = (string) ($index['fileId'] ?? '');
            }

            if ($fileIds === []) {
                continue;
            }

            $fileIdsByProjectId[(string) $mod['id']] = array_values(array_unique($fileIds));

            foreach ($mod['latestFiles'] as $file) {
                if (is_array($file) && isset($file['id'])) {
                    $filesById[(string) $file['id']] = $file;
                }
            }
        }

        $requiredFileIds = array_values(array_unique(array_merge(...array_values($fileIdsByProjectId))));
        $missingFileIds = array_values(array_diff($requiredFileIds, array_keys($filesById)));

        if ($missingFileIds !== []) {
            $bulkFilesStartedAt = microtime(true);
            $bulkFilesResponse = $this->getBulkFiles($missingFileIds);
            $returnedFiles = is_array($bulkFilesResponse['data'] ?? null) ? $bulkFilesResponse['data'] : [];

            $this->logBulkFilesTiming(
                $bulkFilesStartedAt,
                count($missingFileIds),
                count($returnedFiles),
                (int) ceil(count($missingFileIds) / self::BULK_FILES_CHUNK_SIZE),
            );

            foreach ($returnedFiles as $file) {
                if (is_array($file) && isset($file['id'])) {
                    $filesById[(string) $file['id']] = $file;
                }
            }
        }

        $versionsByProjectId = [];

        foreach ($fileIdsByProjectId as $projectId => $fileIds) {
            $files = [];
            foreach ($fileIds as $fileId) {
                if (!isset($filesById[$fileId])) {
                    continue 2;
                }

                $files[] = $filesById[$fileId];
            }

            $versionsByProjectId[$projectId] = ['data' => $files];
        }

        return $versionsByProjectId;
    }

    /**
     * @param array<int, string> $fileIds
     * @return array<string, mixed>
     */
    protected function getBulkFiles(array $fileIds): array
    {
        $files = [];

        foreach (array_chunk(array_values(array_unique($fileIds)), self::BULK_FILES_CHUNK_SIZE) as $chunk) {
            $response = $this->postJson('/mods/files', [
                'fileIds' => array_map('intval', $chunk),
            ]);

            foreach ($response['data'] ?? [] as $file) {
                if (is_array($file)) {
                    $files[] = $file;
                }
            }
        }

        return ['data' => $files];
    }

    protected function logBulkVersionsTiming(float $startedAt, int $requestedProjectCount, int $returnedProjectCount): void
    {
        logger()->info('Mod manager timing', [
            'stage' => 'curseforge_versions_bulk_request',
            'request_id' => request()->attributes->get('mmr_timing_request_id'),
            'endpoint' => '/mods',
            'started_after_ms' => $this->getModManagerTimingElapsedMs($startedAt),
            'finished_after_ms' => $this->getModManagerTimingElapsedMs(),
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'requested_project_count' => $requestedProjectCount,
            'returned_project_count' => $returnedProjectCount,
        ]);
    }

    protected function logBulkFilesTiming(float $startedAt, int $requestedFileCount, int $returnedFileCount, int $requestCount): void
    {
        logger()->info('Mod manager timing', [
            'stage' => 'curseforge_files_bulk_request',
            'request_id' => request()->attributes->get('mmr_timing_request_id'),
            'endpoint' => '/mods/files',
            'started_after_ms' => $this->getModManagerTimingElapsedMs($startedAt),
            'finished_after_ms' => $this->getModManagerTimingElapsedMs(),
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'requested_file_count' => $requestedFileCount,
            'returned_file_count' => $returnedFileCount,
            'request_count' => $requestCount,
        ]);
    }

    protected function getModManagerTimingElapsedMs(?float $timestamp = null): ?int
    {
        $startedAt = request()->attributes->get('mmr_timing_started_at');

        if (!is_float($startedAt)) {
            return null;
        }

        return (int) round((($timestamp ?? microtime(true)) - $startedAt) * 1000);
    }

    /** @return array<string, int|string>|null */
    protected function getVersionRequestParams(Server $server, ProjectType $type): ?array
    {
        if (!$this->isConfigured()) {
            return null;
        }

        $params = [
            'gameVersion' => MinecraftVersionResolver::resolve($server),
            'pageSize' => 50,
        ];

        if ($this->classIdFor($type) === self::CLASS_ID_MOD) {
            $modLoaderType = $this->modLoaderTypeFor($server);

            if ($modLoaderType === null) {
                return null;
            }

            $params['modLoaderType'] = $modLoaderType;
        }

        return $params;
    }

    /**
     * @param array<string, mixed> $response
     * @return array<int, mixed>
     */
    protected function normalizeVersions(array $response): array
    {
        $versions = collect($response['data'] ?? [])
            ->filter(fn ($file) => is_array($file) && ($file['isAvailable'] ?? true))
            ->map(fn (array $file) => $this->normalizeVersion($file))
            ->values()
            ->all();

        usort($versions, fn ($a, $b) => strcmp($b['date_published'] ?? '', $a['date_published'] ?? ''));

        return $versions;
    }

    /**
     * @param array<string, string> $hashesByFilename [filename => murmur2 fingerprint as a decimal string]
     * @return array<string, mixed> [fingerprint => normalized version data]
     */
    public function findVersionsByHash(array $hashesByFilename): array
    {
        if (empty($hashesByFilename) || !$this->isConfigured()) {
            return [];
        }

        $fingerprints = [];
        foreach ($hashesByFilename as $hash) {
            if (ctype_digit((string) $hash)) {
                $fingerprints[] = (int) $hash;
            }
        }

        $fingerprints = array_values(array_unique($fingerprints));

        if (empty($fingerprints)) {
            return [];
        }

        $response = $this->postJson('/fingerprints', ['fingerprints' => $fingerprints]);

        $exactMatches = $response['data']['exactMatches'] ?? [];

        if (!is_array($exactMatches)) {
            return [];
        }

        $results = [];
        foreach ($exactMatches as $match) {
            $file = is_array($match) ? ($match['file'] ?? null) : null;

            if (!is_array($file) || !isset($file['fileFingerprint'])) {
                continue;
            }

            $version = $this->normalizeVersion($file);
            $version['project_id'] = (string) ($file['modId'] ?? '');

            $results[(string) $file['fileFingerprint']] = $version;
        }

        return $results;
    }

    /** @return array<string, mixed>|null */
    public function resolveProjectByIdentifier(string $identifier): ?array
    {
        if (!$this->isConfigured()) {
            return null;
        }

        if (ctype_digit($identifier)) {
            return $this->getProject($identifier);
        }

        foreach ([self::CLASS_ID_MOD, self::CLASS_ID_PLUGIN] as $classId) {
            $response = $this->getJson('/mods/search', [
                'gameId' => self::GAME_ID,
                'classId' => $classId,
                'slug' => $identifier,
                'pageSize' => 1,
            ]);

            $mod = $response['data'][0] ?? null;

            if (is_array($mod) && ($mod['slug'] ?? null) === $identifier) {
                return $this->normalizeProject($mod);
            }
        }

        return null;
    }

    protected function classIdFor(ProjectType $type): ?int
    {
        return match ($type) {
            ProjectType::Mod => self::CLASS_ID_MOD,
            ProjectType::Plugin => self::CLASS_ID_PLUGIN,
            ProjectType::Datapack => null,
        };
    }

    /**
     * Maps the server's (source-agnostic) loader to CurseForge's ModLoaderType enum.
     * Only meaningful for classId=6 (Mods); Bukkit Plugins (classId=5) aren't
     * filtered by loader on CurseForge.
     */
    protected function modLoaderTypeFor(Server $server): ?int
    {
        return match (MinecraftLoader::fromServer($server)) {
            MinecraftLoader::Forge => 1,
            MinecraftLoader::Fabric => 4,
            MinecraftLoader::Quilt => 5,
            MinecraftLoader::NeoForge => 6,
            default => null,
        };
    }

    /** @param array<string, mixed> $mod */
    protected function normalizeProject(array $mod, ?ProjectType $type = null): array
    {
        $logo = $mod['logo'] ?? null;
        $author = $mod['authors'][0]['name'] ?? null;

        return [
            'project_id' => (string) ($mod['id'] ?? ''),
            'slug' => $mod['slug'] ?? '',
            'title' => $mod['name'] ?? '',
            'description' => $mod['summary'] ?? '',
            'icon_url' => $logo['thumbnailUrl'] ?? $logo['url'] ?? null,
            'author' => (is_string($author) && $author !== '') ? $author : null,
            'downloads' => (int) ($mod['downloadCount'] ?? 0),
            'date_modified' => $mod['dateModified'] ?? null,
            'project_type' => $type?->value ?? '',
        ];
    }

    /**
     * @param array<string, mixed> $file
     * @return array<string, mixed>
     */
    protected function normalizeVersion(array $file): array
    {
        $releaseType = match ($file['releaseType'] ?? null) {
            2 => 'beta',
            3 => 'alpha',
            default => 'release',
        };

        $hashes = [];
        foreach (($file['hashes'] ?? []) as $hash) {
            if (!is_array($hash) || !isset($hash['value'])) {
                continue;
            }

            $algo = match ($hash['algo'] ?? null) {
                1 => 'sha1',
                2 => 'md5',
                default => null,
            };

            if ($algo) {
                $hashes[$algo] = $hash['value'];
            }
        }

        return [
            'id' => (string) ($file['id'] ?? ''),
            'version_number' => $file['displayName'] ?? $file['fileName'] ?? '',
            'version_type' => $releaseType,
            // CurseForge's file listing only exposes a mod-level downloadCount, not
            // a per-file one, so this is always 0.
            'downloads' => 0,
            'date_published' => $file['fileDate'] ?? null,
            // Changelogs require a separate request per file
            // (GET /mods/{modId}/files/{fileId}/changelog); not fetched here to
            // avoid an N+1 request per version when listing.
            'changelog' => null,
            'featured' => false,
            'files' => [
                [
                    'primary' => true,
                    'filename' => $file['fileName'] ?? '',
                    // Null when the mod author disabled third-party download links.
                    'url' => $file['downloadUrl'] ?? null,
                    'hashes' => $hashes,
                ],
            ],
        ];
    }

    /** @param array<string, mixed> $query */
    protected function getJson(string $path, array $query = []): array
    {
        try {
            $response = Http::asJson()
                ->withHeaders(['x-api-key' => $this->apiKey()])
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

    /** @param array<string, mixed> $body */
    protected function postJson(string $path, array $body): array
    {
        try {
            $response = Http::asJson()
                ->withHeaders(['x-api-key' => $this->apiKey()])
                ->timeout(10)
                ->connectTimeout(5)
                ->throw()
                ->post(self::BASE_URL.$path, $body)
                ->json();

            return is_array($response) ? $response : [];
        } catch (Exception $exception) {
            report($exception);

            return [];
        }
    }

    protected function apiKey(): string
    {
        return (string) config('pelican-minecraft-modrinth.curseforge_api_key');
    }
}
