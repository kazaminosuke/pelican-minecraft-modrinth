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

class HangarSource implements ProjectSourceInterface
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

    public function supportsProjectType(ModrinthProjectType $type): bool
    {
        return $type === ModrinthProjectType::Plugin;
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
    public function search(Server $server, ModrinthProjectType $type, int $page = 1, ?string $search = null): array
    {
        if ($type !== ModrinthProjectType::Plugin) {
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
    public function getVersions(string $projectId, Server $server, ModrinthProjectType $type): array
    {
        if ($type !== ModrinthProjectType::Plugin) {
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

            if (!is_array($project) || !isset($project['id'])) {
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
     * never looked up again, with no explicit invalidation needed. Only
     * successful matches are cached; a miss isn't, since a project could be
     * published to Hangar after this file was last scanned.
     */
    protected function findVersionEntryByHash(string $projectId, string $hash): ?array
    {
        $cacheKey = "hangar_hash_match:$hash";
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
            'project_type' => ModrinthProjectType::Plugin->value,
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
