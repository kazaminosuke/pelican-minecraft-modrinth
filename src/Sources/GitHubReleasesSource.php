<?php

namespace Boy132\MinecraftModrinth\Sources;

use App\Models\Server;
use Boy132\MinecraftModrinth\Contracts\ProjectSourceInterface;
use Boy132\MinecraftModrinth\Enums\ModrinthProjectType;
use Boy132\MinecraftModrinth\Enums\ProjectSourceKey;
use Exception;
use Illuminate\Support\Facades\Http;

/**
 * Lightweight "direct tracking" source: unlike Modrinth/CurseForge/Hangar, GitHub
 * Releases has no searchable catalog, so a project is identified and added by its
 * "owner/repo" identifier rather than found via search().
 *
 * It also has no Minecraft-version/loader compatibility metadata, so every
 * non-draft release is returned as-is by getVersions() - the admin is responsible
 * for picking the right one, same as they would on the repo's Releases page.
 */
class GitHubReleasesSource implements ProjectSourceInterface
{
    protected const BASE_URL = 'https://api.github.com';

    public function getKey(): ProjectSourceKey
    {
        return ProjectSourceKey::GitHubReleases;
    }

    public function getLabel(): string
    {
        return 'GitHub Releases';
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
        return in_array($type, [ModrinthProjectType::Mod, ModrinthProjectType::Plugin], true);
    }

    public function supportsSearch(): bool
    {
        return false;
    }

    public function supportsHashLookup(): bool
    {
        // GitHub release assets do carry a sha256 `digest` field, but there is no
        // API to search *across* GitHub for a matching hash - you can only check
        // assets of a repo you already know. That's the reverse-lookup capability
        // findVersionsByHash() needs, so it isn't usable here.
        return false;
    }

    public function getHashAlgorithm(): ?string
    {
        return null;
    }

    public function supportsDirectIdentifier(): bool
    {
        return true;
    }

    /** @return array{hits: array<int, array<string, mixed>>, total_hits: int} */
    public function search(Server $server, ModrinthProjectType $type, int $page = 1, ?string $search = null, array $filters = []): array
    {
        return ['hits' => [], 'total_hits' => 0];
    }

    /** @return array<string, mixed>|null */
    public function getProject(string $projectId): ?array
    {
        return $this->resolveProjectByIdentifier($projectId);
    }

    /**
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
        $repo = $this->parseIdentifier($projectId);

        if ($repo === null) {
            return [];
        }

        [$owner, $name] = $repo;

        $cacheKey = "github_releases:$owner/$name";

        $response = cache()->remember($cacheKey, now()->addMinutes(30), fn () => $this->getJson("/repos/$owner/$name/releases", ['per_page' => 30]));

        if (!is_array($response)) {
            return [];
        }

        return collect($response)
            ->filter(fn ($release) => is_array($release) && !($release['draft'] ?? false))
            ->map(fn (array $release) => $this->normalizeVersion($release))
            ->filter(fn ($version) => $version !== null)
            ->values()
            ->all();
    }

    /**
     * @param array<string, string> $hashesByFilename
     * @return array<string, mixed>
     */
    public function findVersionsByHash(array $hashesByFilename): array
    {
        return [];
    }

    /** @return array<string, mixed>|null */
    public function resolveProjectByIdentifier(string $identifier): ?array
    {
        $repo = $this->parseIdentifier($identifier);

        if ($repo === null) {
            return null;
        }

        [$owner, $name] = $repo;

        return cache()->remember("github_repo:$owner/$name", now()->addMinutes(30), function () use ($owner, $name) {
            $response = $this->getJson("/repos/$owner/$name");

            return isset($response['id']) ? $this->normalizeProject($response) : null;
        });
    }

    /** @return array{0: string, 1: string}|null [owner, repo] */
    protected function parseIdentifier(string $identifier): ?array
    {
        if (!preg_match('#^([\w.\-]+)/([\w.\-]+)$#', trim($identifier), $matches)) {
            return null;
        }

        return [$matches[1], $matches[2]];
    }

    /** @param array<string, mixed> $repo */
    protected function normalizeProject(array $repo): array
    {
        return [
            'project_id' => $repo['full_name'] ?? '',
            'slug' => $repo['full_name'] ?? '',
            'title' => $repo['name'] ?? '',
            'description' => $repo['description'] ?? '',
            'icon_url' => $repo['owner']['avatar_url'] ?? null,
            'author' => $repo['owner']['login'] ?? null,
            // GitHub doesn't expose a repo-level download counter.
            'downloads' => 0,
            'date_modified' => $repo['pushed_at'] ?? $repo['updated_at'] ?? null,
            'project_type' => '',
        ];
    }

    /**
     * @param array<string, mixed> $release
     * @return array<string, mixed>|null  null when the release has no .jar assets
     */
    protected function normalizeVersion(array $release): ?array
    {
        $assets = collect($release['assets'] ?? [])
            ->filter(fn ($asset) => is_array($asset) && str_ends_with(strtolower($asset['name'] ?? ''), '.jar'))
            ->values();

        if ($assets->isEmpty()) {
            return null;
        }

        $files = $assets->map(function (array $asset, int $index) {
            $hashes = [];
            $digest = $asset['digest'] ?? null;

            if (is_string($digest) && str_starts_with($digest, 'sha256:')) {
                $hashes['sha256'] = substr($digest, 7);
            }

            return [
                'primary' => $index === 0,
                'filename' => $asset['name'] ?? '',
                'url' => $asset['browser_download_url'] ?? null,
                'hashes' => $hashes,
            ];
        })->values()->all();

        $totalDownloads = collect($release['assets'] ?? [])->sum(fn ($asset) => is_array($asset) ? ($asset['download_count'] ?? 0) : 0);

        return [
            // The release tag is used as the update-detection identifier, per the
            // same string-comparison contract isUpdateAvailable() already uses.
            'id' => (string) ($release['tag_name'] ?? $release['id'] ?? ''),
            'version_number' => $release['tag_name'] ?? ($release['name'] ?? ''),
            'version_type' => ($release['prerelease'] ?? false) ? 'beta' : 'release',
            'downloads' => (int) $totalDownloads,
            'date_published' => $release['published_at'] ?? $release['created_at'] ?? null,
            'changelog' => $release['body'] ?? null,
            'featured' => false,
            'files' => $files,
        ];
    }

    /** @param array<string, mixed> $query */
    protected function getJson(string $path, array $query = []): array
    {
        try {
            $request = Http::asJson()->withHeaders([
                'Accept' => 'application/vnd.github+json',
                'X-GitHub-Api-Version' => '2022-11-28',
            ]);

            $token = $this->token();

            if ($token !== '') {
                $request = $request->withToken($token);
            }

            $response = $request
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

    protected function token(): string
    {
        return (string) config('pelican-minecraft-modrinth.github_token');
    }
}
