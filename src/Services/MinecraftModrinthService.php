<?php

namespace Boy132\MinecraftModrinth\Services;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use Boy132\MinecraftModrinth\Contracts\ProjectSourceInterface;
use Boy132\MinecraftModrinth\Enums\ModrinthProjectType;
use Boy132\MinecraftModrinth\Enums\ProjectSourceKey;
use Boy132\MinecraftModrinth\Sources\CurseForgeSource;
use Boy132\MinecraftModrinth\Sources\HangarSource;
use Boy132\MinecraftModrinth\Sources\ModrinthSource;
use Boy132\MinecraftModrinth\Support\CacheVersion;
use Boy132\MinecraftModrinth\Support\CurseForgeFingerprint;
use Boy132\MinecraftModrinth\Support\MinecraftVersionResolver;
use Exception;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class MinecraftModrinthService
{
    public function __construct(
        protected ModrinthSource $source,
        protected CurseForgeSource $curseForgeSource,
        protected HangarSource $hangarSource,
    ) {}

    /** @var array<int, array<string, string>|null> */
    protected array $serverPropertiesCache = [];

    /** @param array<string, mixed> $context */
    protected function logModManagerTiming(string $stage, float $startedAt, array $context = []): void
    {
        $requestId = request()->attributes->get('mmr_timing_request_id');
        $requestStartedAt = request()->attributes->get('mmr_timing_started_at');

        if (!is_string($requestId) || !is_float($requestStartedAt)) {
            return;
        }

        $finishedAt = microtime(true);

        Log::info('Mod manager timing', array_merge($context, [
            'stage' => $stage,
            'request_id' => $requestId,
            'started_after_ms' => (int) round(($startedAt - $requestStartedAt) * 1000),
            'finished_after_ms' => (int) round(($finishedAt - $requestStartedAt) * 1000),
            'duration_ms' => (int) round(($finishedAt - $startedAt) * 1000),
        ]));
    }

    public function getMinecraftVersion(Server $server): ?string
    {
        return MinecraftVersionResolver::resolve($server);
    }

    /** @return array{hits: array<int, array<string, mixed>>, total_hits: int} */
    public function getModrinthProjects(Server $server, int $page = 1, ?string $search = null, ?ModrinthProjectType $type = null): array
    {
        $type ??= ModrinthProjectType::fromServer($server);

        if (!$type) {
            return [
                'hits' => [],
                'total_hits' => 0,
            ];
        }

        return $this->source->search($server, $type, $page, $search);
    }

    /**
     * @param array<int, array{project_id: string, project_slug: string, project_title: string, version_id: string, version_number: string, filename: string, installed_at: string, author?: string}> $installedMods
     * @return array<int, array<string, mixed>>
     */
    public function getInstalledModsFromModrinth(array $installedMods, int $page = 1): array
    {
        return $this->source->getInstalledModsFromModrinth($installedMods, $page);
    }

    /** @return array<int, mixed> */
    public function getModrinthVersions(string $projectId, Server $server, ?ModrinthProjectType $type = null): array
    {
        $type ??= ModrinthProjectType::fromServer($server);

        if (!$type) {
            return [];
        }

        return $this->source->getVersions($projectId, $server, $type);
    }

    /**
     * @param array<string, string> $hashMap [filename => sha512hash]
     * @return array<string, mixed> [sha512hash => versionData]
     */
    public function lookupVersionsByHashes(array $hashMap): array
    {
        return $this->source->findVersionsByHash($hashMap);
    }

    /**
     * Scans the mods/plugins folder, hashes unknown JARs, looks them up on Modrinth,
     * imports matches into metadata, and returns filenames not found on Modrinth.
     *
     * @return array<string>  Filenames with no Modrinth match
     *
     * @throws Exception
     */
    public function scanAndImportMods(Server $server, DaemonFileRepository $fileRepository, ?ModrinthProjectType $type = null): array
    {
        $startedAt = microtime(true);
        $resolvedType = $type ?? ModrinthProjectType::fromServer($server);
        $cacheKey = $this->getHashScanCacheKey($server, $resolvedType);
        $cacheHit = Cache::has($cacheKey);

        $unknownFiles = Cache::remember($cacheKey, now()->addMinutes(10), function () use ($server, $fileRepository, $resolvedType) {
            return $this->performScan($server, $fileRepository, $resolvedType);
        });

        $this->logModManagerTiming('installed_scan', $startedAt, [
            'cache_hit' => $cacheHit,
            'unknown_files_count' => count($unknownFiles),
        ]);

        return $unknownFiles;
    }

    public function getHashScanCacheKey(Server $server, ?ModrinthProjectType $type = null): string
    {
        $resolvedType = $type ?? ModrinthProjectType::fromServer($server);

        return "{$this->source->getKey()->value}_hash_scan:{$server->id}:".($resolvedType?->value ?? 'unknown');
    }

    /**
     * Deletes this server's installed-mods metadata file - both the current
     * (.pelican-mod-manager.json) and legacy (.modrinth-metadata.json)
     * filenames, since either could be the one actually in use - and clears
     * the caches that would otherwise keep serving stale results
     * afterwards: the hydration display cache and the 10-minute
     * scanAndImportMods() cache. Without clearing the latter, the very next
     * "Installed" tab load would silently reuse a cached pre-deletion scan
     * result instead of noticing every file is now unknown again, which is
     * exactly what was observed when this file was deleted by hand.
     *
     * Deleting the metadata file does not, by itself, cause anything to be
     * re-scanned - see resetInstalledMods() for that.
     *
     * @throws Exception
     */
    public function clearInstalledModsMetadata(Server $server, DaemonFileRepository $fileRepository, ?ModrinthProjectType $type = null): void
    {
        $type ??= ModrinthProjectType::fromServer($server);
        $folder = $this->resolveMetadataFolder($server, $fileRepository, $type);

        try {
            $fileRepository->setServer($server)->deleteFiles($folder, [
                '.pelican-mod-manager.json',
                '.modrinth-metadata.json',
            ]);
        } catch (Exception $exception) {
            report($exception);
        }

        CacheVersion::bumpHydration($server);
        cache()->forget($this->getHashScanCacheKey($server, $type));
    }

    /**
     * Clears this server's installed-mods metadata and caches (see
     * clearInstalledModsMetadata()) and immediately re-scans, so the
     * Installed tab reflects a clean, freshly re-matched state right away -
     * rather than requiring a manual "Update all mods"/rescan click to
     * notice the metadata is gone, which is what happened before this
     * existed.
     *
     * @return array<string> Filenames with no match after the fresh scan.
     *
     * @throws Exception
     */
    public function resetInstalledMods(Server $server, DaemonFileRepository $fileRepository, ?ModrinthProjectType $type = null): array
    {
        $type ??= ModrinthProjectType::fromServer($server);

        $this->clearInstalledModsMetadata($server, $fileRepository, $type);

        return $this->scanAndImportMods($server, $fileRepository, $type);
    }

    public function getProjectFolder(Server $server, DaemonFileRepository $fileRepository, ?ModrinthProjectType $type = null): string
    {
        $resolvedType = $type ?? ModrinthProjectType::fromServer($server);

        if ($resolvedType !== ModrinthProjectType::Datapack) {
            return $resolvedType?->getFolder($server) ?? 'mods';
        }

        return $this->getDatapackWorldName($server, $fileRepository).'/datapacks';
    }

    public function getDatapackWorldName(Server $server, DaemonFileRepository $fileRepository): string
    {
        $worldName = trim((string) $this->getServerPropertiesValue($server, $fileRepository, 'level-name'), " \t\n\r\0\x0B/\\");

        return $worldName !== '' ? $worldName : 'world';
    }

    protected function getServerPropertiesValue(Server $server, DaemonFileRepository $fileRepository, string $key): ?string
    {
        if (!array_key_exists($server->id, $this->serverPropertiesCache)) {
            $this->serverPropertiesCache[$server->id] = $this->getServerProperties($server, $fileRepository);
        }

        $properties = $this->serverPropertiesCache[$server->id];

        return $properties ? ($properties[$key] ?? null) : null;
    }

    /** @return array<string, string>|null */
    protected function getServerProperties(Server $server, DaemonFileRepository $fileRepository): ?array
    {
        try {
            $content = $fileRepository->setServer($server)->getContent('server.properties');
        } catch (Exception $exception) {
            return null;
        }

        if (empty($content)) {
            return null;
        }

        $properties = [];
        foreach (preg_split('/\r\n|\r|\n/', $content) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }

            [$propertyKey, $value] = array_map('trim', explode('=', $line, 2));
            $properties[$propertyKey] = $value;
        }

        return $properties;
    }

    /**
     * @return array<string>  Filenames with no Modrinth match
     *
     * @throws Exception
     */
    protected function performScan(Server $server, DaemonFileRepository $fileRepository, ?ModrinthProjectType $type = null): array
    {
        // Large modpacks require several remote file reads. This applies only to
        // the explicit installed-file scan, not to the rest of the request.
        set_time_limit(120);

        $type ??= ModrinthProjectType::fromServer($server);

        if (!$type) {
            return [];
        }

        $directory = $this->getProjectFolder($server, $fileRepository, $type);

        try {
            $directoryContents = $fileRepository->setServer($server)->getDirectory($directory);
        } catch (Exception $exception) {
            report($exception);
            $this->logWingsRequestFailure(
                'installed_scan_directory',
                $server,
                "/api/servers/{$server->uuid}/files/list-directory",
                ['directory' => $directory],
                $exception,
            );

            return [];
        }

        if (!is_array($directoryContents) || isset($directoryContents['error'])) {
            return [];
        }

        $extension = $type->getFileExtension();

        $diskFiles = collect($directoryContents)
            ->filter(fn ($item) => is_array($item) && isset($item['name']) && str($item['name'])->lower()->endsWith($extension))
            ->pluck('name')
            ->values()
            ->toArray();

        $installedModsMetadata = $this->getInstalledModsMetadata($server, $fileRepository, $type);

        $diskFilesLower = array_flip(array_map('strtolower', $diskFiles));
        $filteredInstalledModsMetadata = array_values(array_filter(
            $installedModsMetadata,
            fn ($installedMod) => isset($diskFilesLower[strtolower($installedMod['filename'])])
        ));

        if (count($filteredInstalledModsMetadata) !== count($installedModsMetadata)) {
            $this->saveInstalledModsMetadata($server, $fileRepository, $filteredInstalledModsMetadata, $type);
        }

        $installedModsMetadata = $filteredInstalledModsMetadata;

        if (empty($diskFiles)) {
            return [];
        }

        $knownFilenames = [];
        foreach ($installedModsMetadata as $installedMod) {
            $knownFilenames[strtolower($installedMod['filename'])] = true;
        }

        $unknownFiles = array_values(
            array_filter($diskFiles, function ($name) use ($knownFilenames) {
                $normalizedName = strtolower($name);

                return !isset($knownFilenames[$normalizedName]);
            })
        );

        if (empty($unknownFiles)) {
            return [];
        }

        $matchedFilenames = [];
        $remainingFilenames = $unknownFiles;
        $folder = $this->getProjectFolder($server, $fileRepository, $type);
        $hashResolutionStartedAt = microtime(true);

        foreach ($this->getHashLookupSourcesInPriorityOrder() as $hashSource) {
            if (empty($remainingFilenames)) {
                break;
            }

            if (!$hashSource->isConfigured() || !$hashSource->supportsHashLookup()) {
                continue;
            }

            $algorithm = $hashSource->getHashAlgorithm();

            if ($algorithm === null) {
                continue;
            }

            $hashMap = []; // [filename => hash]
            $hashComputationStartedAt = microtime(true);
            foreach ($remainingFilenames as $filename) {
                try {
                    $hashMap[$filename] = $this->computeDaemonFileHash($fileRepository, $server, "{$folder}/{$filename}", $algorithm);
                } catch (Exception $exception) {
                    report($exception);
                }
            }

            $this->logModManagerTiming('hash_computation', $hashComputationStartedAt, [
                'source' => $hashSource->getKey()->value,
                'algorithm' => $algorithm,
                'files_count' => count($remainingFilenames),
                'hashed_files_count' => count($hashMap),
            ]);

            $hashLookupStartedAt = microtime(true);
            $versionsByHash = $hashSource->findVersionsByHash($hashMap);

            $this->logModManagerTiming('hash_lookup', $hashLookupStartedAt, [
                'source' => $hashSource->getKey()->value,
                'hashes_count' => count($hashMap),
                'matches_count' => count($versionsByHash),
            ]);

            if (empty($versionsByHash)) {
                continue;
            }

            $hashToFilenames = [];
            foreach ($hashMap as $filename => $hash) {
                if (!isset($hashToFilenames[$hash])) {
                    $hashToFilenames[$hash] = [];
                }

                $hashToFilenames[$hash][] = $filename;
            }

            $matchedVersions = []; // [filename => versionData]
            $projectIds = [];

            foreach ($versionsByHash as $hash => $versionData) {
                if (!isset($hashToFilenames[$hash]) || !is_array($versionData) || !isset($versionData['project_id'])) {
                    continue;
                }

                foreach ($hashToFilenames[$hash] as $filename) {
                    $matchedVersions[$filename] = $versionData;
                }

                $projectIds[] = $versionData['project_id'];
            }

            if (empty($matchedVersions)) {
                continue;
            }

            $projectLookupStartedAt = microtime(true);
            try {
                $projectsMap = $hashSource->getProjectsByIds(array_unique($projectIds));
            } catch (Exception $exception) {
                report($exception);
                $projectsMap = [];
            }

            $this->logModManagerTiming('hash_project_lookup', $projectLookupStartedAt, [
                'source' => $hashSource->getKey()->value,
                'project_ids_count' => count(array_unique($projectIds)),
                'projects_count' => count($projectsMap),
            ]);

            $metadataPersistenceStartedAt = microtime(true);
            foreach ($matchedVersions as $filename => $versionData) {
                if (!isset($versionData['project_id'], $versionData['id'], $versionData['version_number'])) {
                    continue;
                }

                $projectId = $versionData['project_id'];
                $project = $projectsMap[$projectId] ?? null;

                $saved = $this->saveModMetadata(
                    server: $server,
                    fileRepository: $fileRepository,
                    projectId: $projectId,
                    projectSlug: $project['slug'] ?? $projectId,
                    projectTitle: $project['title'] ?? $projectId,
                    versionId: $versionData['id'],
                    versionNumber: $versionData['version_number'],
                    filename: $filename,
                    author: $this->resolveMatchAuthor($hashSource, $project, $versionData),
                    type: $type,
                    source: $hashSource->getKey(),
                );

                if ($saved) {
                    $matchedFilenames[] = $filename;
                }
            }

            $this->logModManagerTiming('hash_metadata_persistence', $metadataPersistenceStartedAt, [
                'source' => $hashSource->getKey()->value,
                'matched_files_count' => count($matchedVersions),
                'saved_files_count' => count($matchedFilenames),
            ]);

            $remainingFilenames = array_values(array_diff($remainingFilenames, $matchedFilenames));
        }

        $this->logModManagerTiming('hash_resolution', $hashResolutionStartedAt, [
            'unknown_files_count' => count($unknownFiles),
            'matched_files_count' => count($matchedFilenames),
            'remaining_files_count' => count($remainingFilenames),
        ]);

        return array_values(
            array_filter($unknownFiles, fn ($name) => !in_array($name, $matchedFilenames, true))
        );
    }

    /**
     * Sources to try, in priority order, when identifying unknown files by hash
     * during a scan. CurseForge and Modrinth resolve a hash match straight to an
     * exact version. Hangar's hash endpoint only identifies the parent project
     * and needs an expensive follow-up scan of that project's versions to pin
     * down the exact file (see HangarSource::findVersionEntryByHash()), so it's
     * tried last and only against files the cheaper sources didn't already
     * resolve - Hangar-exclusive plugins are a minority, so this avoids paying
     * that cost for files that are actually on Modrinth or CurseForge.
     *
     * @return array<int, ProjectSourceInterface>
     */
    protected function getHashLookupSourcesInPriorityOrder(): array
    {
        return [$this->curseForgeSource, $this->source, $this->hangarSource];
    }

    /**
     * Streams a daemon file into the hash algorithm expected by a source.
     */
    protected function computeDaemonFileHash(DaemonFileRepository $fileRepository, Server $server, string $path, string $algorithm): string
    {
        if ($algorithm === 'murmur2') {
            return (string) CurseForgeFingerprint::hashStream(fn () => $this->openDaemonFileStream($fileRepository, $server, $path));
        }

        if (!in_array($algorithm, ['sha512', 'sha256'], true)) {
            return '';
        }

        $stream = $this->openDaemonFileStream($fileRepository, $server, $path);
        $hash = hash_init($algorithm);

        try {
            while (!$stream->eof()) {
                $chunk = $stream->read(1024 * 1024);
                if ($chunk !== '') {
                    hash_update($hash, $chunk);
                }
            }
        } finally {
            $stream->close();
        }

        return hash_final($hash);
    }

    /** Opens a Wings response without converting its body into a string. */
    protected function openDaemonFileStream(DaemonFileRepository $fileRepository, Server $server, string $path): object
    {
        $response = $fileRepository->setServer($server)->getHttpClient()->withOptions(['stream' => true])->get("/api/servers/{$server->uuid}/files/contents", ['file' => $path]);

        return $response->toPsrResponse()->getBody();
    }

    /**
     * Modrinth's raw project data doesn't reliably include an author, so
     * ModrinthSource resolves it separately via resolveAuthor(). The other
     * sources already bake author into their normalized project data.
     *
     * @param array<string, mixed>|null $project
     * @param array<string, mixed> $versionData
     */
    protected function resolveMatchAuthor(ProjectSourceInterface $hashSource, ?array $project, array $versionData): ?string
    {
        if ($hashSource instanceof ModrinthSource) {
            return $hashSource->resolveAuthor($project, $versionData);
        }

        $author = $project['author'] ?? null;

        return (is_string($author) && $author !== '') ? $author : null;
    }

    /**
     * @throws Exception
     */
    protected function getMetadataFilePath(Server $server, DaemonFileRepository $fileRepository, ?ModrinthProjectType $type = null): string
    {
        return $this->resolveMetadataFolder($server, $fileRepository, $type).'/.pelican-mod-manager.json';
    }

    /**
     * Path of the metadata file used by plugin versions prior to the multi-source
     * rework. Only ever read from (as a fallback), never written to.
     *
     * @throws Exception
     */
    protected function getLegacyMetadataFilePath(Server $server, DaemonFileRepository $fileRepository, ?ModrinthProjectType $type = null): string
    {
        return $this->resolveMetadataFolder($server, $fileRepository, $type).'/.modrinth-metadata.json';
    }

    /**
     * @throws Exception
     */
    protected function resolveMetadataFolder(Server $server, DaemonFileRepository $fileRepository, ?ModrinthProjectType $type = null): string
    {
        $type ??= ModrinthProjectType::fromServer($server);

        if (!$type) {
            throw new Exception("Server {$server->id} does not support Modrinth mods or plugins");
        }

        return $this->getProjectFolder($server, $fileRepository, $type);
    }

    /**
     * Reads installed-mod metadata from the current metadata file, falling back to
     * the legacy pre-multi-source file (defaulting its entries to the Modrinth
     * source) only when the current file doesn't exist or can't be parsed. An
     * existing-but-empty current file is authoritative and does NOT fall back,
     * so mods removed after a migration don't reappear.
     *
     * @return array<int, array{source: string, project_id: string, project_slug: string, project_title: string, version_id: string, version_number: string, filename: string, installed_at: string, author?: string}>
     */
    public function getInstalledModsMetadata(Server $server, DaemonFileRepository $fileRepository, ?ModrinthProjectType $type = null): array
    {
        try {
            $metadataPath = $this->getMetadataFilePath($server, $fileRepository, $type);
        } catch (Exception $exception) {
            return [];
        }

        $entries = $this->readMetadataEntries($server, $fileRepository, $metadataPath);

        if ($entries !== null) {
            return $entries;
        }

        try {
            $legacyMetadataPath = $this->getLegacyMetadataFilePath($server, $fileRepository, $type);
        } catch (Exception $exception) {
            return [];
        }

        return $this->readMetadataEntries($server, $fileRepository, $legacyMetadataPath) ?? [];
    }

    /**
     * @return array<int, array<string, mixed>>|null  null when the file is missing/unreadable/invalid
     */
    protected function readMetadataEntries(Server $server, DaemonFileRepository $fileRepository, string $metadataPath): ?array
    {
        try {
            $content = $fileRepository->setServer($server)->getContent($metadataPath);
        } catch (Exception $exception) {
            $this->logWingsRequestFailure(
                'installed_metadata_read',
                $server,
                "/api/servers/{$server->uuid}/files/contents",
                ['file' => $metadataPath],
                $exception,
            );

            return null;
        }

        $metadata = json_decode($content, true);

        if (!is_array($metadata) || !isset($metadata['installed_mods']) || !is_array($metadata['installed_mods'])) {
            return null;
        }

        $validInstalledMods = [];
        $requiredKeys = [
            'project_id',
            'project_slug',
            'project_title',
            'version_id',
            'version_number',
            'filename',
            'installed_at',
        ];

        $requiredKeysFlipped = array_flip($requiredKeys);

        foreach ($metadata['installed_mods'] as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $missingKeys = array_diff_key($requiredKeysFlipped, $entry);
            if (empty($missingKeys)) {
                $entry['source'] ??= ProjectSourceKey::Modrinth->value;
                $validInstalledMods[] = $entry;
            }
        }

        return $validInstalledMods;
    }

    /** @param array<string, string> $query */
    protected function logWingsRequestFailure(string $stage, Server $server, string $endpoint, array $query, Exception $exception): void
    {
        $response = $exception instanceof RequestException ? $exception->response : null;
        $configuredUrl = $server->node->getConnectionAddress().$endpoint;

        Log::error('Mod manager Wings request failed', [
            'stage' => $stage,
            'request_id' => request()->attributes->get('mmr_timing_request_id'),
            'method' => 'GET',
            'configured_url' => $configuredUrl,
            'requested_url' => $configuredUrl.'?'.http_build_query($query),
            'effective_url' => $response?->effectiveUri()?->__toString(),
            'query' => $query,
            'http_status' => $response?->status(),
            'wings_request_id' => $response?->header('X-Request-Id') ?: $response?->json('request_id'),
            'wings_error' => $response?->json('error'),
            'server_id' => $server->id,
            'server_uuid' => $server->uuid,
            'server_updated_at' => $server->updated_at?->toIso8601String(),
            'node_id' => $server->node_id,
            'node_updated_at' => $server->node->updated_at?->toIso8601String(),
            'exception_class' => $exception::class,
        ]);
    }

    public function saveModMetadata(
        Server $server,
        DaemonFileRepository $fileRepository,
        string $projectId,
        string $projectSlug,
        string $projectTitle,
        string $versionId,
        string $versionNumber,
        string $filename,
        ?string $author = null,
        ?ModrinthProjectType $type = null,
        ProjectSourceKey $source = ProjectSourceKey::Modrinth
    ): bool {
        try {
            return Cache::lock("modrinth_metadata:{$server->id}", 10)->block(5, function () use ($server, $fileRepository, $projectId, $projectSlug, $projectTitle, $versionId, $versionNumber, $filename, $author, $type, $source) {
                $metadata = [
                    'installed_mods' => $this->getInstalledModsMetadata($server, $fileRepository, $type),
                ];

                $metadata['installed_mods'] = collect($metadata['installed_mods'])
                    ->filter(fn ($mod) => !($mod['source'] === $source->value && $mod['project_id'] === $projectId) && strtolower($mod['filename']) !== strtolower($filename))
                    ->values()
                    ->toArray();

                $modEntry = [
                    'source' => $source->value,
                    'project_id' => $projectId,
                    'project_slug' => $projectSlug,
                    'project_title' => $projectTitle,
                    'version_id' => $versionId,
                    'version_number' => $versionNumber,
                    'filename' => $filename,
                    'installed_at' => now()->toIso8601String(),
                ];

                if ($author !== null) {
                    $modEntry['author'] = $author;
                }

                $metadata['installed_mods'][] = $modEntry;

                $metadataPath = $this->getMetadataFilePath($server, $fileRepository, $type);
                $response = $fileRepository->setServer($server)->putContent(
                    $metadataPath,
                    json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
                );

                if ($response->failed()) {
                    return false;
                }

                CacheVersion::bumpHydration($server);

                return true;
            }) === true;
        } catch (Exception $exception) {
            report($exception);

            return false;
        }
    }

    /**
     * @param array<int, array{source: string, project_id: string, project_slug: string, project_title: string, version_id: string, version_number: string, filename: string, installed_at: string, author?: string}> $installedMods
     */
    protected function saveInstalledModsMetadata(Server $server, DaemonFileRepository $fileRepository, array $installedMods, ?ModrinthProjectType $type = null): bool
    {
        try {
            return Cache::lock("modrinth_metadata:{$server->id}", 10)->block(5, function () use ($server, $fileRepository, $installedMods, $type) {
                $metadataPath = $this->getMetadataFilePath($server, $fileRepository, $type);
                $response = $fileRepository->setServer($server)->putContent(
                    $metadataPath,
                    json_encode(['installed_mods' => array_values($installedMods)], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
                );

                if ($response->failed()) {
                    return false;
                }

                CacheVersion::bumpHydration($server);

                return true;
            }) === true;
        } catch (Exception $exception) {
            report($exception);

            return false;
        }
    }

    public function removeModMetadata(Server $server, DaemonFileRepository $fileRepository, string $projectId, ?ModrinthProjectType $type = null, ProjectSourceKey $source = ProjectSourceKey::Modrinth): bool
    {
        try {
            return Cache::lock("modrinth_metadata:{$server->id}", 10)->block(5, function () use ($server, $fileRepository, $projectId, $type, $source) {
                $metadata = [
                    'installed_mods' => $this->getInstalledModsMetadata($server, $fileRepository, $type),
                ];

                $metadata['installed_mods'] = collect($metadata['installed_mods'])
                    ->filter(fn ($mod) => !($mod['source'] === $source->value && $mod['project_id'] === $projectId))
                    ->values()
                    ->toArray();

                $metadataPath = $this->getMetadataFilePath($server, $fileRepository, $type);
                $response = $fileRepository->setServer($server)->putContent(
                    $metadataPath,
                    json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
                );

                if ($response->failed()) {
                    return false;
                }

                CacheVersion::bumpHydration($server);

                return true;
            }) === true;
        } catch (Exception $exception) {
            report($exception);

            return false;
        }
    }

    /** @return array{source: string, project_id: string, project_slug: string, project_title: string, version_id: string, version_number: string, filename: string, installed_at: string, author?: string}|null */
    public function getInstalledMod(Server $server, DaemonFileRepository $fileRepository, string $projectId, ?ModrinthProjectType $type = null, ProjectSourceKey $source = ProjectSourceKey::Modrinth): ?array
    {
        $installedMods = $this->getInstalledModsMetadata($server, $fileRepository, $type);

        foreach ($installedMods as $mod) {
            if ($mod['project_id'] === $projectId && $mod['source'] === $source->value) {
                return $mod;
            }
        }

        return null;
    }

    /**
     * @param array{version_id: string, version_number: string} $installedMod
     * @param array<int, array{id: string, version_number: string}> $availableVersions
     */
    public function isUpdateAvailable(array $installedMod, array $availableVersions): bool
    {
        if (empty($availableVersions)) {
            return false;
        }

        $latestVersion = $availableVersions[0];

        return $installedMod['version_id'] !== $latestVersion['id'];
    }

    /**
     * @return array<string>
     */
    public function getInstalledMods(Server $server, DaemonFileRepository $fileRepository, ?ModrinthProjectType $type = null): array
    {
        $metadata = $this->getInstalledModsMetadata($server, $fileRepository, $type);

        return collect($metadata)
            ->pluck('filename')
            ->map(fn ($name) => strtolower($name))
            ->toArray();
    }
}
