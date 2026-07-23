<?php

namespace Boy132\MinecraftModrinth\Sources;

use App\Models\Server;
use Boy132\MinecraftModrinth\Contracts\ProjectSourceInterface;
use Boy132\MinecraftModrinth\Enums\MinecraftLoader;
use Boy132\MinecraftModrinth\Enums\ModrinthProjectType;
use Boy132\MinecraftModrinth\Enums\ProjectSourceKey;
use Boy132\MinecraftModrinth\Support\MinecraftVersionResolver;
use Exception;
use Illuminate\Support\Facades\Http;

class CurseForgeSource implements ProjectSourceInterface
{
    protected const BASE_URL = 'https://api.curseforge.com/v1';

    /** Minecraft's numeric CurseForge gameId. */
    protected const GAME_ID = 432;

    /** CurseForge classId for "Minecraft Mods". */
    protected const CLASS_ID_MOD = 6;

    /** CurseForge classId for "Bukkit Plugins". */
    protected const CLASS_ID_PLUGIN = 5;

    /** CurseForge's ModsSearchSortField enum value for sorting by total download count. */
    protected const SORT_FIELD_TOTAL_DOWNLOADS = 6;

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

    public function supportsProjectType(ModrinthProjectType $type): bool
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
    public function search(Server $server, ModrinthProjectType $type, int $page = 1, ?string $search = null, array $filters = []): array
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
            // sortField/sortOrder are both optional and, left unset, CurseForge
            // falls back to its own internal ordering (roughly "Featured"),
            // which is unrelated to download count and reads as effectively
            // random next to Modrinth's tab. 6 = TotalDownloads in
            // CurseForge's ModsSearchSortField enum (1 Featured, 2 Popularity,
            // 3 LastUpdated, 4 Name, 5 Author, 6 TotalDownloads, 7 Category,
            // 8 GameVersion, 9 EarlyAccess, 10 FeaturedRelease, 11 ReleaseDate,
            // 12 Rating), matching Modrinth's tab so both read the same way.
            'sortField' => match ($filters['sort'] ?? 'downloads') { 'updated' => 3, 'popularity' => 2, default => self::SORT_FIELD_TOTAL_DOWNLOADS },
            'sortOrder' => 'desc',
        ];

        if ($classId === self::CLASS_ID_MOD) {
            $modLoaderType = $this->modLoaderTypeFor($server);

            if ($modLoaderType === null) {
                return ['hits' => [], 'total_hits' => 0];
            }

            $params['modLoaderType'] = $modLoaderType;
        }

        if (!empty($filters['category'])) { $params['categoryId'] = (int) $filters['category']; }

        if ($search) {
            $params['searchFilter'] = $search;
        }

        $cacheKey = 'curseforge_search:'.md5(json_encode($params));

        $response = cache()->remember($cacheKey, now()->addMinutes(30), fn () => $this->getJson('/mods/search', $params));

        if (($filters['sort'] ?? null) === 'updated') {
            logger()->debug('CurseForge updated search response', [
                'query' => $params,
                'category' => $filters['category'] ?? null,
                'first_dates' => collect($response['data'] ?? [])->take(10)->map(fn ($mod) => is_array($mod) ? ($mod['dateModified'] ?? null) : null)->all(),
            ]);
        }

        $hits = collect($response['data'] ?? [])
            ->filter(fn ($mod) => is_array($mod))
            ->map(fn (array $mod) => $this->normalizeProject($mod, $type))
            ->values()
            ->all();

        return [
            'hits' => $hits,
            'total_hits' => (int) ($response['pagination']['totalCount'] ?? count($hits)),
        ];
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
    public function getVersions(string $projectId, Server $server, ModrinthProjectType $type): array
    {
        if (!$this->isConfigured()) {
            return [];
        }

        $params = [
            'gameVersion' => MinecraftVersionResolver::resolve($server),
            'pageSize' => 50,
        ];

        if ($this->classIdFor($type) === self::CLASS_ID_MOD) {
            $modLoaderType = $this->modLoaderTypeFor($server);

            if ($modLoaderType === null) {
                return [];
            }

            $params['modLoaderType'] = $modLoaderType;
        }

        $cacheKey = "curseforge_files:$projectId:".md5(json_encode($params));

        $response = cache()->remember($cacheKey, now()->addMinutes(30), fn () => $this->getJson("/mods/$projectId/files", $params));

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

    protected function classIdFor(ModrinthProjectType $type): ?int
    {
        return match ($type) {
            ModrinthProjectType::Mod => self::CLASS_ID_MOD,
            ModrinthProjectType::Plugin => self::CLASS_ID_PLUGIN,
            ModrinthProjectType::Datapack => null,
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
    protected function normalizeProject(array $mod, ?ModrinthProjectType $type = null): array
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
