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
use Boy132\MinecraftModrinth\Support\CurseForgeFingerprint;
use Boy132\MinecraftModrinth\Support\MinecraftVersionResolver;
use Exception;
use Illuminate\Support\Facades\Cache;

class MinecraftModrinthService
{
    public function __construct(
        protected ModrinthSource $source,
        protected CurseForgeSource $curseForgeSource,
        protected HangarSource $hangarSource,
    ) {}

    /** @var array<int, array<string, string>|null> */
    protected array $serverPropertiesCache = [];

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
        $resolvedType = $type ?? ModrinthProjectType::fromServer($server);
        $cacheKey = $this->getHashScanCacheKey($server, $resolvedType);

        return cache()->remember($cacheKey, now()->addMinutes(10), function () use ($server, $fileRepository, $resolvedType) {
            return $this->performScan($server, $fileRepository, $resolvedType);
        });
    }

    public function getHashScanCacheKey(Server $server, ?ModrinthProjectType $type = null): string
    {
        $resolvedType = $type ?? ModrinthProjectType::fromServer($server);

        return "{$this->source->getKey()->value}_hash_scan:{$server->id}:".($resolvedType?->value ?? 'unknown');
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
        $type ??= ModrinthProjectType::fromServer($server);

        if (!$type) {
            return [];
        }

        try {
            $directoryContents = $fileRepository->setServer($server)->getDirectory($this->getProjectFolder($server, $fileRepository, $type));
        } catch (Exception $exception) {
            report($exception);

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
            foreach ($remainingFilenames as $filename) {
                try {
                    $hashMap[$filename] = $this->computeDaemonFileHash($fileRepository, $server, "{$folder}/{$filename}", $algorithm);
                } catch (Exception $exception) {
                    report($exception);
                }
            }

            $versionsByHash = $hashSource->findVersionsByHash($hashMap);

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

            try {
                $projectsMap = $hashSource->getProjectsByIds(array_unique($projectIds));
            } catch (Exception $exception) {
                report($exception);
                $projectsMap = [];
            }

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

            $remainingFilenames = array_values(array_diff($remainingFilenames, $matchedFilenames));
        }

        return array_values(
            array_filter($unknownFiles, fn ($name) => !in_array($name, $matchedFilenames, true))
        );
    }

    /**
     * Sources to try, in priority order, when identifying unknown files by hash
     * during a scan. Modrinth and CurseForge resolve a hash match straight to an
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
        return [$this->source, $this->curseForgeSource, $this->hangarSource];
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

                return !$response->failed();
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

                return !$response->failed();
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

                return !$response->failed();
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
