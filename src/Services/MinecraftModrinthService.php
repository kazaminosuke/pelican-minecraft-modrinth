<?php

namespace Boy132\MinecraftModrinth\Services;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use Boy132\MinecraftModrinth\Contracts\ProjectSourceInterface;
use Boy132\MinecraftModrinth\Enums\ModrinthProjectType;
use Boy132\MinecraftModrinth\Enums\ProjectSourceKey;
use Boy132\MinecraftModrinth\Repositories\InstalledMetadataRepository;
use Boy132\MinecraftModrinth\Sources\CurseForgeSource;
use Boy132\MinecraftModrinth\Sources\HangarSource;
use Boy132\MinecraftModrinth\Sources\ModrinthSource;
use Boy132\MinecraftModrinth\Support\CacheVersion;
use Boy132\MinecraftModrinth\Support\CurseForgeFingerprint;
use Boy132\MinecraftModrinth\Support\InstalledMetadataDocument;
use Boy132\MinecraftModrinth\Support\InstalledMetadataReadResult;
use Boy132\MinecraftModrinth\Support\InstalledMetadataReadStatus;
use Boy132\MinecraftModrinth\Support\InstalledScanResult;
use Boy132\MinecraftModrinth\Support\MinecraftVersionResolver;
use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class MinecraftModrinthService
{
    private const HASH_SCAN_CACHE_MINUTES = 10;

    private const HASH_SCAN_LOCK_SECONDS = 180;

    public function __construct(
        protected ModrinthSource $source,
        protected CurseForgeSource $curseForgeSource,
        protected HangarSource $hangarSource,
        protected InstalledMetadataRepository $metadataRepository,
    ) {}

    /** @var array<int, array<string, string>|null> */
    protected array $serverPropertiesCache = [];

    protected int $hashScanWingsGetCount = 0;

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

    /** @return array<int, string> */
    public function scanAndImportMods(Server $server, DaemonFileRepository $fileRepository, ?ModrinthProjectType $type = null): array
    {
        return $this->scanAndImportModsResult($server, $fileRepository, $type)->unknownFiles;
    }

    public function scanAndImportModsResult(Server $server, DaemonFileRepository $fileRepository, ?ModrinthProjectType $type = null): InstalledScanResult
    {
        set_time_limit(240);

        $startedAt = microtime(true);
        $resolvedType = $type ?? ModrinthProjectType::fromServer($server);
        $cacheKey = $this->getHashScanCacheKey($server, $resolvedType);
        $cachedResult = InstalledScanResult::fromCache(Cache::get($cacheKey));
        $scanExecuted = false;
        $this->hashScanWingsGetCount = 0;

        if ($cachedResult !== null) {
            $result = $cachedResult;
        } else {
            $lock = Cache::lock($cacheKey.':lock', self::HASH_SCAN_LOCK_SECONDS);

            if (!$lock->get()) {
                $result = InstalledScanResult::failed('scan_in_progress');
            } else {
                try {
                    $cachedAfterLock = InstalledScanResult::fromCache(Cache::get($cacheKey));

                    if ($cachedAfterLock !== null) {
                        $result = $cachedAfterLock;
                    } else {
                        $scanExecuted = true;
                        $result = $this->performScan($server, $fileRepository, $resolvedType);

                        // A normal empty folder is a successful, authoritative
                        // result and is cacheable. Transport errors and malformed
                        // Wings responses are failures and must never poison the
                        // Installed tab with a cached empty result.
                        if ($result->successful) {
                            Cache::put($cacheKey, $result->toCachePayload(), now()->addMinutes(self::HASH_SCAN_CACHE_MINUTES));
                        }
                    }
                } finally {
                    $lock->release();
                }
            }
        }

        $this->logModManagerTiming('installed_scan', $startedAt, [
            'cache_key' => $cacheKey,
            'cache_hit' => $result->cacheHit,
            'scan_executed' => $scanExecuted,
            'successful' => $result->successful,
            'failure' => $result->failure,
            'wings_get_count' => $this->hashScanWingsGetCount,
            'disk_file_count' => $result->diskFileCount,
            'unknown_files_count' => count($result->unknownFiles),
        ]);

        return $result;
    }

    public function getHashScanCacheKey(Server $server, ?ModrinthProjectType $type = null): string
    {
        $resolvedType = $type ?? ModrinthProjectType::fromServer($server);
        $typeKey = $resolvedType instanceof ModrinthProjectType ? $resolvedType->value : 'unknown';

        return "installed_scan:v2:{$server->id}:{$typeKey}";
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

    protected function performScan(Server $server, DaemonFileRepository $fileRepository, ?ModrinthProjectType $type = null): InstalledScanResult
    {
        set_time_limit(120);

        $type ??= ModrinthProjectType::fromServer($server);

        if (!$type) {
            return InstalledScanResult::failed('unsupported_project_type');
        }

        try {
            $folder = $this->getProjectFolder($server, $fileRepository, $type);
            $directoryContents = $fileRepository->setServer($server)->getDirectory($folder);
        } catch (Exception $exception) {
            report($exception);

            return InstalledScanResult::failed('wings_directory_unavailable');
        }

        if (!is_array($directoryContents) || isset($directoryContents['error'])) {
            return InstalledScanResult::failed('wings_directory_invalid');
        }

        $extension = $type->getFileExtension();
        $diskFiles = [];

        foreach ($directoryContents as $item) {
            if (!is_array($item)
                || !is_string($item['name'] ?? null)
                || !str($item['name'])->lower()->endsWith($extension)
                || ($item['directory'] ?? false) === true
                || (array_key_exists('file', $item) && $item['file'] !== true)) {
                continue;
            }

            $filename = $item['name'];
            $key = strtolower($filename);
            $signature = $this->normalizeFileSignature($item);

            // A case-insensitive filename collision is not safe to identify by
            // the persisted index. Keep the first display name, but force a
            // fresh hash by discarding its reusable signature.
            if (isset($diskFiles[$key])) {
                $diskFiles[$key]['file_signature'] = null;

                continue;
            }

            $diskFiles[$key] = [
                'filename' => $filename,
                'file_signature' => $signature,
            ];
        }

        $metadataResult = $this->metadataRepository->read($server, $fileRepository, $folder);

        if (in_array($metadataResult->status, [InstalledMetadataReadStatus::Invalid, InstalledMetadataReadStatus::Unavailable], true)) {
            return InstalledScanResult::failed('metadata_unavailable');
        }

        $originalDocument = $metadataResult->document;
        $installedByFilename = [];
        foreach ($originalDocument->installedMods() as $entry) {
            $filename = $entry['filename'] ?? null;

            if (is_string($filename) && $filename !== '') {
                $installedByFilename[strtolower($filename)] = $entry;
            }
        }

        $unresolvedByFilename = [];
        foreach ($originalDocument->unresolvedFiles() as $entry) {
            $filename = $entry['filename'] ?? null;

            if (is_string($filename) && $filename !== '') {
                $unresolvedByFilename[strtolower($filename)] = $entry;
            }
        }

        $scannedInstalled = [];
        $filesToResolve = [];
        $hashesByFilename = [];
        $reusedHashCount = 0;

        foreach ($diskFiles as $key => $diskFile) {
            $existingInstalled = $installedByFilename[$key] ?? null;
            $indexed = $existingInstalled ?? ($unresolvedByFilename[$key] ?? null);
            $reusableHashes = is_array($indexed)
                ? $this->reusableHashes($indexed, $diskFile['file_signature'])
                : null;

            if ($existingInstalled !== null && $reusableHashes !== null) {
                $existingInstalled['file_signature'] = $diskFile['file_signature'];
                $existingInstalled['hashes'] = $reusableHashes;
                $scannedInstalled[] = $existingInstalled;

                continue;
            }

            $filename = $diskFile['filename'];
            $filesToResolve[$filename] = $diskFile;

            if ($reusableHashes !== null) {
                $hashesByFilename[$filename] = $reusableHashes;
                $reusedHashCount++;
            }
        }

        $hashResolutionStartedAt = microtime(true);
        $hashComputationStartedAt = microtime(true);
        $hashFailures = [];

        foreach ($filesToResolve as $filename => $diskFile) {
            if (isset($hashesByFilename[$filename])) {
                continue;
            }

            try {
                $hashesByFilename[$filename] = $this->computeDaemonFileHashes(
                    $fileRepository,
                    $server,
                    "{$folder}/{$filename}",
                );
            } catch (Exception $exception) {
                report($exception);
                $hashFailures[] = $filename;
            }
        }

        $this->logModManagerTiming('hash_computation', $hashComputationStartedAt, [
            'source' => 'shared',
            'algorithms' => ['murmur2', 'sha512', 'sha256'],
            'files_count' => count($filesToResolve),
            'hashed_files_count' => count($hashesByFilename) - $reusedHashCount,
            'reused_hashes_count' => $reusedHashCount,
            'wings_get_count' => $this->hashScanWingsGetCount,
            'failed_files_count' => count($hashFailures),
        ]);

        $remainingFilenames = array_keys($filesToResolve);
        $matchedEntries = [];
        $lookupFailures = [];

        foreach ($this->getHashLookupSourcesInPriorityOrder() as $hashSource) {
            if ($remainingFilenames === []) {
                break;
            }

            if (!$hashSource->isConfigured() || !$hashSource->supportsHashLookup()) {
                continue;
            }

            $algorithm = $hashSource->getHashAlgorithm();

            if ($algorithm === null) {
                continue;
            }

            $hashMap = [];
            foreach ($remainingFilenames as $filename) {
                $hash = $hashesByFilename[$filename][$algorithm] ?? null;

                if (is_string($hash) && $hash !== '') {
                    $hashMap[$filename] = $hash;
                }
            }

            if ($hashMap === []) {
                continue;
            }

            $hashLookupStartedAt = microtime(true);
            try {
                $versionsByHash = $hashSource->findVersionsByHash($hashMap);
            } catch (Exception $exception) {
                report($exception);
                $lookupFailures[] = $hashSource->getKey()->value;
                $versionsByHash = [];
            }

            $this->logModManagerTiming('hash_lookup', $hashLookupStartedAt, [
                'source' => $hashSource->getKey()->value,
                'hashes_count' => count($hashMap),
                'matches_count' => count($versionsByHash),
            ]);

            if ($versionsByHash === []) {
                continue;
            }

            $hashToFilenames = [];
            foreach ($hashMap as $filename => $hash) {
                $hashToFilenames[$hash][] = $filename;
            }

            $matchedVersions = [];
            $projectIds = [];
            foreach ($versionsByHash as $hash => $versionData) {
                if (!isset($hashToFilenames[$hash]) || !is_array($versionData) || !isset($versionData['project_id'])) {
                    continue;
                }

                foreach ($hashToFilenames[$hash] as $filename) {
                    $matchedVersions[$filename] = $versionData;
                }

                $projectIds[] = (string) $versionData['project_id'];
            }

            if ($matchedVersions === []) {
                continue;
            }

            $projectLookupStartedAt = microtime(true);
            try {
                $projectsMap = $hashSource->getProjectsByIds(array_values(array_unique($projectIds)));
            } catch (Exception $exception) {
                report($exception);
                $projectsMap = [];
            }

            $this->logModManagerTiming('hash_project_lookup', $projectLookupStartedAt, [
                'source' => $hashSource->getKey()->value,
                'project_ids_count' => count(array_unique($projectIds)),
                'projects_count' => count($projectsMap),
            ]);

            foreach ($matchedVersions as $filename => $versionData) {
                if (!isset($versionData['project_id'], $versionData['id'], $versionData['version_number'])) {
                    continue;
                }

                $projectId = (string) $versionData['project_id'];
                $project = $projectsMap[$projectId] ?? null;
                $entry = [
                    'source' => $hashSource->getKey()->value,
                    'project_id' => $projectId,
                    'project_slug' => $project['slug'] ?? $projectId,
                    'project_title' => $project['title'] ?? $projectId,
                    'version_id' => (string) $versionData['id'],
                    'version_number' => (string) $versionData['version_number'],
                    'filename' => $filename,
                    'installed_at' => now()->toIso8601String(),
                    'file_signature' => $filesToResolve[$filename]['file_signature'],
                    'hashes' => $hashesByFilename[$filename],
                ];
                $author = $this->resolveMatchAuthor($hashSource, $project, $versionData);

                if ($author !== null) {
                    $entry['author'] = $author;
                }

                $matchedEntries[$filename] = $entry;
            }

            $remainingFilenames = array_values(array_diff($remainingFilenames, array_keys($matchedEntries)));
        }

        foreach ($matchedEntries as $entry) {
            $scannedInstalled = $this->upsertInstalledEntry($scannedInstalled, $entry);
        }

        $scannedUnresolved = [];
        foreach ($remainingFilenames as $filename) {
            $entry = [
                'filename' => $filename,
                'file_signature' => $filesToResolve[$filename]['file_signature'],
                'last_checked_at' => now()->toIso8601String(),
            ];

            if (isset($hashesByFilename[$filename])) {
                $entry['hashes'] = $hashesByFilename[$filename];
            }

            $scannedUnresolved[] = $entry;
        }

        $metadataPersistenceStartedAt = microtime(true);
        $saved = $this->metadataRepository->mutate(
            $server,
            $fileRepository,
            $folder,
            fn (InstalledMetadataDocument $latest): InstalledMetadataDocument => $this->rebaseScanDocument(
                $originalDocument,
                $latest,
                $scannedInstalled,
                $scannedUnresolved,
            ),
        );

        $this->logModManagerTiming('hash_metadata_persistence', $metadataPersistenceStartedAt, [
            'source' => 'all',
            'matched_files_count' => count($matchedEntries),
            'saved_files_count' => $saved ? count($matchedEntries) : 0,
            'writes_count' => $saved ? 1 : 0,
        ]);

        $failure = !$saved
            ? 'metadata_write_failed'
            : ($hashFailures !== []
                ? 'hash_computation_partial_failure'
                : ($lookupFailures !== [] ? 'hash_lookup_partial_failure' : null));

        $this->logModManagerTiming('hash_resolution', $hashResolutionStartedAt, [
            'unknown_files_count' => count($filesToResolve),
            'matched_files_count' => count($matchedEntries),
            'remaining_files_count' => count($remainingFilenames),
            'wings_get_count' => $this->hashScanWingsGetCount,
            'failure' => $failure,
        ]);

        if ($failure !== null) {
            return InstalledScanResult::failed($failure, $remainingFilenames, count($diskFiles));
        }

        return InstalledScanResult::success($remainingFilenames, count($diskFiles));
    }

    /**
     * @param array<string, mixed> $item
     * @return array{size: int, modified_at: string}|null
     */
    protected function normalizeFileSignature(array $item): ?array
    {
        $size = $item['size'] ?? null;
        $modified = $item['modified'] ?? null;

        if (!is_numeric($size) || (!is_string($modified) && !is_numeric($modified))) {
            return null;
        }

        return [
            'size' => (int) $size,
            'modified_at' => (string) $modified,
        ];
    }

    /**
     * @param array<string, mixed> $entry
     * @param array{size: int, modified_at: string}|null $signature
     * @return array{murmur2: string, sha512: string, sha256: string}|null
     */
    protected function reusableHashes(array $entry, ?array $signature): ?array
    {
        if ($signature === null || ($entry['file_signature'] ?? null) !== $signature || !is_array($entry['hashes'] ?? null)) {
            return null;
        }

        $hashes = [];
        foreach (['murmur2', 'sha512', 'sha256'] as $algorithm) {
            $hash = $entry['hashes'][$algorithm] ?? null;

            if (!is_string($hash) || $hash === '') {
                return null;
            }

            $hashes[$algorithm] = $hash;
        }

        return $hashes;
    }

    /**
     * Rebase the scan onto metadata changes made while hashing. New installs,
     * updates, and removals win over the older directory snapshot.
     *
     * @param array<int, array<string, mixed>> $scannedInstalled
     * @param array<int, array<string, mixed>> $scannedUnresolved
     */
    protected function rebaseScanDocument(
        InstalledMetadataDocument $original,
        InstalledMetadataDocument $latest,
        array $scannedInstalled,
        array $scannedUnresolved,
    ): InstalledMetadataDocument {
        $originalInstalled = $this->indexInstalledEntries($original->installedMods());
        $latestInstalled = $this->indexInstalledEntries($latest->installedMods());

        foreach ($originalInstalled as $identity => $entry) {
            if (!isset($latestInstalled[$identity])) {
                $scannedInstalled = array_values(array_filter(
                    $scannedInstalled,
                    fn (array $candidate): bool => $this->installedEntryIdentity($candidate) !== $identity,
                ));
            } elseif ($latestInstalled[$identity] != $entry) {
                $scannedInstalled = $this->upsertInstalledEntry($scannedInstalled, $latestInstalled[$identity]);
            }
        }

        foreach ($latestInstalled as $identity => $entry) {
            if (!isset($originalInstalled[$identity])) {
                $scannedInstalled = $this->upsertInstalledEntry($scannedInstalled, $entry);
            }
        }

        $originalUnresolved = $this->indexEntriesByFilename($original->unresolvedFiles());
        $latestUnresolved = $this->indexEntriesByFilename($latest->unresolvedFiles());
        $scannedUnresolved = $this->indexEntriesByFilename($scannedUnresolved);

        foreach ($originalUnresolved as $filename => $entry) {
            if (!isset($latestUnresolved[$filename])) {
                unset($scannedUnresolved[$filename]);
            } elseif ($latestUnresolved[$filename] != $entry) {
                $scannedUnresolved[$filename] = $latestUnresolved[$filename];
            }
        }

        foreach ($latestUnresolved as $filename => $entry) {
            if (!isset($originalUnresolved[$filename])) {
                $scannedUnresolved[$filename] = $entry;
            }
        }

        return $latest
            ->withInstalledMods(array_values($scannedInstalled))
            ->withUnresolvedFiles(array_values($scannedUnresolved));
    }

    /**
     * @param array<int, array<string, mixed>> $entries
     * @return array<string, array<string, mixed>>
     */
    protected function indexInstalledEntries(array $entries): array
    {
        $indexed = [];

        foreach ($entries as $entry) {
            $indexed[$this->installedEntryIdentity($entry)] = $entry;
        }

        return $indexed;
    }

    /**
     * @param array<int, array<string, mixed>> $entries
     * @return array<string, array<string, mixed>>
     */
    protected function indexEntriesByFilename(array $entries): array
    {
        $indexed = [];

        foreach ($entries as $entry) {
            $filename = strtolower((string) ($entry['filename'] ?? ''));

            if ($filename !== '') {
                $indexed[$filename] = $entry;
            }
        }

        return $indexed;
    }

    /** @param array<string, mixed> $entry */
    protected function installedEntryIdentity(array $entry): string
    {
        return ($entry['source'] ?? ProjectSourceKey::Modrinth->value).':'.($entry['project_id'] ?? '').':'.strtolower((string) ($entry['filename'] ?? ''));
    }

    /**
     * @param array<int, array<string, mixed>> $entries
     * @param array<string, mixed> $entry
     * @return array<int, array<string, mixed>>
     */
    protected function upsertInstalledEntry(array $entries, array $entry): array
    {
        $identity = $this->installedEntryIdentity($entry);
        $filename = strtolower((string) ($entry['filename'] ?? ''));
        $entries = array_values(array_filter(
            $entries,
            fn (array $candidate): bool => $this->installedEntryIdentity($candidate) !== $identity
                && strtolower((string) ($candidate['filename'] ?? '')) !== $filename,
        ));
        $entries[] = $entry;

        return $entries;
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
     * Downloads a daemon file once and computes every hash needed by the
     * installed-source resolvers during that single streaming pass.
     *
     * @return array{murmur2: string, sha512: string, sha256: string}
     */
    protected function computeDaemonFileHashes(DaemonFileRepository $fileRepository, Server $server, string $path): array
    {
        $sha512 = hash_init('sha512');
        $sha256 = hash_init('sha256');

        $murmur2 = CurseForgeFingerprint::hashStream(
            fn () => $this->openDaemonFileStream($fileRepository, $server, $path),
            static function (string $chunk) use ($sha512, $sha256): void {
                hash_update($sha512, $chunk);
                hash_update($sha256, $chunk);
            },
        );

        return [
            'murmur2' => (string) $murmur2,
            'sha512' => hash_final($sha512),
            'sha256' => hash_final($sha256),
        ];
    }

    /** Opens a Wings response without converting its body into a string. */
    protected function openDaemonFileStream(DaemonFileRepository $fileRepository, Server $server, string $path): object
    {
        $this->hashScanWingsGetCount++;
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
    protected function resolveMetadataFolder(Server $server, DaemonFileRepository $fileRepository, ?ModrinthProjectType $type = null): string
    {
        $type ??= ModrinthProjectType::fromServer($server);

        if (!$type) {
            throw new Exception("Server {$server->id} does not support Modrinth mods or plugins");
        }

        return $this->getProjectFolder($server, $fileRepository, $type);
    }

    /**
     * Read the complete installed metadata document, including the persistent
     * file-signature and hash index used by incremental scans.
     */
    public function getInstalledMetadataReadResult(Server $server, DaemonFileRepository $fileRepository, ?ModrinthProjectType $type = null): InstalledMetadataReadResult
    {
        try {
            $folder = $this->resolveMetadataFolder($server, $fileRepository, $type);
        } catch (Exception) {
            return new InstalledMetadataReadResult(
                InstalledMetadataDocument::empty(),
                InstalledMetadataReadStatus::Unavailable,
            );
        }

        return $this->metadataRepository->read($server, $fileRepository, $folder);
    }

    /** @return array<int, array<string, mixed>> */
    public function getInstalledModsMetadata(Server $server, DaemonFileRepository $fileRepository, ?ModrinthProjectType $type = null): array
    {
        return $this->getInstalledMetadataReadResult($server, $fileRepository, $type)->document->installedMods();
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
            $folder = $this->resolveMetadataFolder($server, $fileRepository, $type);
        } catch (Exception $exception) {
            report($exception);

            return false;
        }

        $entry = [
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
            $entry['author'] = $author;
        }

        return $this->metadataRepository->mutate(
            $server,
            $fileRepository,
            $folder,
            function (InstalledMetadataDocument $document) use ($entry, $source, $projectId, $filename): InstalledMetadataDocument {
                $installedMods = array_values(array_filter(
                    $document->installedMods(),
                    fn (array $mod): bool => !(($mod['source'] ?? ProjectSourceKey::Modrinth->value) === $source->value && ($mod['project_id'] ?? null) === $projectId)
                        && strtolower((string) ($mod['filename'] ?? '')) !== strtolower($filename),
                ));
                $installedMods[] = $entry;

                $unresolvedFiles = array_values(array_filter(
                    $document->unresolvedFiles(),
                    fn (array $file): bool => strtolower((string) ($file['filename'] ?? '')) !== strtolower($filename),
                ));

                return $document
                    ->withInstalledMods($installedMods)
                    ->withUnresolvedFiles($unresolvedFiles);
            },
        );
    }

    /** @param array<int, array<string, mixed>> $installedMods */
    protected function saveInstalledModsMetadata(Server $server, DaemonFileRepository $fileRepository, array $installedMods, ?ModrinthProjectType $type = null): bool
    {
        try {
            $folder = $this->resolveMetadataFolder($server, $fileRepository, $type);
        } catch (Exception $exception) {
            report($exception);

            return false;
        }

        return $this->metadataRepository->mutate(
            $server,
            $fileRepository,
            $folder,
            fn (InstalledMetadataDocument $document): InstalledMetadataDocument => $document->withInstalledMods(array_values($installedMods)),
        );
    }

    public function saveInstalledMetadataDocument(Server $server, DaemonFileRepository $fileRepository, InstalledMetadataDocument $document, ?ModrinthProjectType $type = null): bool
    {
        try {
            $folder = $this->resolveMetadataFolder($server, $fileRepository, $type);
        } catch (Exception $exception) {
            report($exception);

            return false;
        }

        return $this->metadataRepository->replace($server, $fileRepository, $folder, $document);
    }

    public function removeModMetadata(Server $server, DaemonFileRepository $fileRepository, string $projectId, ?ModrinthProjectType $type = null, ProjectSourceKey $source = ProjectSourceKey::Modrinth): bool
    {
        try {
            $folder = $this->resolveMetadataFolder($server, $fileRepository, $type);
        } catch (Exception $exception) {
            report($exception);

            return false;
        }

        return $this->metadataRepository->mutate(
            $server,
            $fileRepository,
            $folder,
            function (InstalledMetadataDocument $document) use ($projectId, $source): InstalledMetadataDocument {
                $installedMods = array_values(array_filter(
                    $document->installedMods(),
                    fn (array $mod): bool => !(($mod['source'] ?? ProjectSourceKey::Modrinth->value) === $source->value && ($mod['project_id'] ?? null) === $projectId),
                ));

                return $document->withInstalledMods($installedMods);
            },
        );
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
