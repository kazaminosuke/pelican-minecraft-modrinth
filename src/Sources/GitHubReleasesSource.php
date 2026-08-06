<?php

namespace Kazaminosuke\ModManager\Sources;

use App\Models\Server;
use Kazaminosuke\ModManager\Contracts\BatchLatestVersionSourceInterface;
use Kazaminosuke\ModManager\Contracts\ProjectSourceInterface;
use Kazaminosuke\ModManager\Enums\ProjectType;
use Kazaminosuke\ModManager\Enums\ProjectSourceKey;
use Kazaminosuke\ModManager\Support\LatestVersionLookupRequest;
use Kazaminosuke\ModManager\Support\LatestVersionLookupResult;
use Exception;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Lightweight "direct tracking" source: unlike Modrinth/CurseForge/Hangar, GitHub
 * Releases has no searchable catalog, so a project is identified and added by its
 * "owner/repo" identifier rather than found via search().
 *
 * It also has no Minecraft-version/loader compatibility metadata, so every
 * non-draft release is returned as-is by getVersions() - the admin is responsible
 * for picking the right one, same as they would on the repo's Releases page.
 */
class GitHubReleasesSource implements BatchLatestVersionSourceInterface, ProjectSourceInterface
{
    protected const BASE_URL = 'https://api.github.com';

    protected const GRAPHQL_BATCH_SIZE = 20;

    protected const REST_POOL_SIZE = 4;

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

    public function supportsProjectType(ProjectType $type): bool
    {
        return in_array($type, [ProjectType::Mod, ProjectType::Plugin], true);
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
    public function search(Server $server, ProjectType $type, int $page = 1, ?string $search = null, array $filters = []): array
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
    public function getVersions(string $projectId, Server $server, ProjectType $type): array
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
     * Resolve only the latest release containing a JAR. This deliberately uses
     * a cache separate from the full release history used by the modal.
     *
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

        if (!$this->supportsProjectType($type)) {
            return new LatestVersionLookupResult(unresolvedKeys: array_map(
                fn (LatestVersionLookupRequest $request): string => $request->key(),
                $requests,
            ));
        }

        $versions = [];
        $unresolved = [];
        $failures = [];
        $requestsByRepository = [];
        $repositories = [];

        foreach ($requests as $request) {
            $parsed = $this->parseIdentifier($request->projectId);

            if ($parsed === null) {
                $unresolved[] = $request->key();
                continue;
            }

            [$owner, $name] = $parsed;
            $repositoryKey = strtolower("$owner/$name");
            $requestsByRepository[$repositoryKey][] = $request;
            $repositories[$repositoryKey] = ['owner' => $owner, 'name' => $name];
        }

        $pending = [];

        foreach ($repositories as $repositoryKey => $repository) {
            $cacheKey = 'github_latest_release:v1:'.$repositoryKey;
            $cached = cache()->get($cacheKey);

            if (is_array($cached)) {
                foreach ($requestsByRepository[$repositoryKey] as $request) {
                    $versions[$request->key()] = $cached;
                }
                continue;
            }

            $pending[$repositoryKey] = [...$repository, 'cache_key' => $cacheKey];
        }

        if ($pending !== []) {
            $resolved = $this->token() !== ''
                ? $this->lookupLatestVersionsWithGraphQl($pending, $requestsByRepository)
                : $this->lookupLatestVersionsWithRestPool($pending, $requestsByRepository);
            $versions = array_replace($versions, $resolved->versions());
            $unresolved = array_merge($unresolved, $resolved->unresolvedKeys());
            $failures = array_replace($failures, $resolved->failures());
        }

        return new LatestVersionLookupResult(
            versionsByKey: $versions,
            unresolvedKeys: $unresolved,
            failuresByKey: $failures,
        );
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

    /**
     * @param array<string, array{owner: string, name: string, cache_key: string}> $pending
     * @param array<string, array<int, LatestVersionLookupRequest>> $requestsByRepository
     */
    protected function lookupLatestVersionsWithGraphQl(array $pending, array $requestsByRepository): LatestVersionLookupResult
    {
        $startedAt = microtime(true);
        $versions = [];
        $unresolved = [];
        $failures = [];
        $requestCount = 0;
        $returnedProjects = 0;

        foreach (array_chunk($pending, self::GRAPHQL_BATCH_SIZE, true) as $chunk) {
            [$query, $variables, $aliases] = $this->buildGraphQlReleaseQuery($chunk);
            $requestCount++;

            try {
                $response = Http::asJson()
                    ->withHeaders([
                        'Accept' => 'application/vnd.github+json',
                        'X-GitHub-Api-Version' => '2022-11-28',
                    ])
                    ->withToken($this->token())
                    ->timeout(10)
                    ->connectTimeout(5)
                    ->throw()
                    ->post(self::BASE_URL.'/graphql', [
                        'query' => $query,
                        'variables' => $variables,
                    ])
                    ->json();

                if (!is_array($response) || !is_array($response['data'] ?? null)) {
                    $message = is_array($response) ? ($response['errors'][0]['message'] ?? null) : null;

                    throw new Exception(is_string($message) ? $message : 'Invalid GitHub GraphQL response');
                }
            } catch (Throwable $exception) {
                report($exception);

                foreach (array_keys($chunk) as $repositoryKey) {
                    $this->recordFailure($failures, $requestsByRepository[$repositoryKey], $exception->getMessage());
                }
                continue;
            }

            $errorsByAlias = $this->graphQlErrorsByAlias($response['errors'] ?? []);

            foreach ($aliases as $alias => $repositoryKey) {
                if (isset($errorsByAlias[$alias])) {
                    $this->recordFailure($failures, $requestsByRepository[$repositoryKey], $errorsByAlias[$alias]);
                    continue;
                }

                $repository = $response['data'][$alias] ?? null;
                if (!is_array($repository)) {
                    $this->recordUnresolved($unresolved, $requestsByRepository[$repositoryKey]);
                    continue;
                }

                $version = $this->latestNormalizedGraphQlVersion($repository);
                if ($version === null) {
                    $this->recordUnresolved($unresolved, $requestsByRepository[$repositoryKey]);
                    continue;
                }

                cache()->put($chunk[$repositoryKey]['cache_key'], $version, now()->addMinutes(30));
                $returnedProjects++;

                foreach ($requestsByRepository[$repositoryKey] as $request) {
                    $versions[$request->key()] = $version;
                }
            }
        }

        $this->logLatestVersionsTiming(
            'github_versions_graphql_request',
            '/graphql',
            $startedAt,
            count($pending),
            $returnedProjects,
            $requestCount,
            count($failures),
        );

        return new LatestVersionLookupResult(
            versionsByKey: $versions,
            unresolvedKeys: $unresolved,
            failuresByKey: $failures,

        );
    }
    /**
     * @param array<string, array{owner: string, name: string, cache_key: string}> $pending
     * @param array<string, array<int, LatestVersionLookupRequest>> $requestsByRepository
     */
    protected function lookupLatestVersionsWithRestPool(array $pending, array $requestsByRepository): LatestVersionLookupResult
    {
        $startedAt = microtime(true);
        $versions = [];
        $unresolved = [];
        $failures = [];
        $requestCount = 0;
        $returnedProjects = 0;

        foreach (array_chunk($pending, self::REST_POOL_SIZE, true) as $chunk) {
            $aliases = [];

            try {
                $responses = Http::pool(function (Pool $pool) use ($chunk, &$aliases) {
                    $poolRequests = [];

                    foreach ($chunk as $repositoryKey => $repository) {
                        $alias = 'github_'.sha1($repositoryKey);
                        $aliases[$alias] = $repositoryKey;
                        $poolRequests[] = $pool->as($alias)
                            ->asJson()
                            ->withHeaders([
                                'Accept' => 'application/vnd.github+json',
                                'X-GitHub-Api-Version' => '2022-11-28',
                            ])
                            ->timeout(10)
                            ->connectTimeout(5)
                            ->get(self::BASE_URL."/repos/{$repository['owner']}/{$repository['name']}/releases", [
                                'per_page' => 30,
                            ]);
                    }

                    return $poolRequests;
                });
                $requestCount += count($chunk);
            } catch (Throwable $exception) {
                report($exception);

                foreach (array_keys($chunk) as $repositoryKey) {
                    $this->recordFailure($failures, $requestsByRepository[$repositoryKey], $exception->getMessage());
                }
                continue;
            }

            foreach ($aliases as $alias => $repositoryKey) {
                try {
                    $response = $responses[$alias] ?? null;

                    if (!$response instanceof Response || !$response->successful()) {
                        throw new Exception("GitHub releases lookup failed for repository $repositoryKey");
                    }

                    $payload = $response->json();
                    if (!is_array($payload)) {
                        throw new Exception("Invalid GitHub releases response for repository $repositoryKey");
                    }

                    $version = $this->latestNormalizedRestVersion($payload);
                } catch (Throwable $exception) {
                    report($exception);
                    $this->recordFailure($failures, $requestsByRepository[$repositoryKey], $exception->getMessage());
                    continue;
                }

                if ($version === null) {
                    $this->recordUnresolved($unresolved, $requestsByRepository[$repositoryKey]);
                    continue;
                }

                cache()->put($chunk[$repositoryKey]['cache_key'], $version, now()->addMinutes(30));
                $returnedProjects++;

                foreach ($requestsByRepository[$repositoryKey] as $request) {
                    $versions[$request->key()] = $version;
                }
            }
        }

        $this->logLatestVersionsTiming(
            'github_versions_rest_parallel_request',
            '/repos/{owner}/{repo}/releases',
            $startedAt,
            count($pending),
            $returnedProjects,
            $requestCount,
            count($failures),
        );

        return new LatestVersionLookupResult(
            versionsByKey: $versions,
            unresolvedKeys: $unresolved,
            failuresByKey: $failures,

        );
    }
    /**
     * @param array<string, array{owner: string, name: string, cache_key: string}> $repositories
     * @return array{0: string, 1: array<string, string>, 2: array<string, string>}
     */
    protected function buildGraphQlReleaseQuery(array $repositories): array
    {
        $definitions = [];
        $variables = [];
        $fields = [];
        $aliases = [];

        foreach (array_keys($repositories) as $index => $repositoryKey) {
            $repository = $repositories[$repositoryKey];
            $alias = "repository_$index";
            $ownerVariable = "owner_$index";
            $nameVariable = "name_$index";
            $definitions[] = '$'.$ownerVariable.': String!';
            $definitions[] = '$'.$nameVariable.': String!';
            $variables[$ownerVariable] = $repository['owner'];
            $variables[$nameVariable] = $repository['name'];
            $aliases[$alias] = $repositoryKey;
            $fields[] = $alias.': repository(owner: $'.$ownerVariable.', name: $'.$nameVariable.') {
                releases(first: 30, orderBy: {field: CREATED_AT, direction: DESC}) {
                    nodes {
                        databaseId
                        name
                        tagName
                        description
                        isDraft
                        isPrerelease
                        createdAt
                        publishedAt
                        releaseAssets(first: 100) {
                            nodes {
                                name
                                downloadUrl
                                downloadCount
                                digest
                            }
                        }
                    }
                }
            }';
        }

        return [
            'query LatestReleases('.implode(', ', $definitions).') { '.implode("\n", $fields).' }',
            $variables,
            $aliases,
        ];
    }

    /**
     * @param array<int, mixed> $errors
     * @return array<string, string>
     */
    protected function graphQlErrorsByAlias(array $errors): array
    {
        $messages = [];

        foreach ($errors as $error) {
            if (!is_array($error)) {
                continue;
            }

            $alias = $error['path'][0] ?? null;
            if (is_string($alias)) {
                $messages[$alias] = (string) ($error['message'] ?? 'GitHub GraphQL lookup failed');
            }
        }


        return $messages;
    }
    /**
     * @param array<string, mixed> $repository
     * @return array<string, mixed>|null
     */
    protected function latestNormalizedGraphQlVersion(array $repository): ?array
    {
        foreach ($repository['releases']['nodes'] ?? [] as $release) {
            if (!is_array($release) || ($release['isDraft'] ?? false)) {
                continue;
            }

            $assets = [];
            foreach ($release['releaseAssets']['nodes'] ?? [] as $asset) {
                if (!is_array($asset)) {
                    continue;
                }

                $assets[] = [
                    'name' => $asset['name'] ?? '',
                    'browser_download_url' => $asset['downloadUrl'] ?? null,
                    'download_count' => $asset['downloadCount'] ?? 0,
                    'digest' => $asset['digest'] ?? null,
                ];
            }

            $normalized = $this->normalizeVersion([
                'id' => $release['databaseId'] ?? '',
                'tag_name' => $release['tagName'] ?? '',
                'name' => $release['name'] ?? '',
                'body' => $release['description'] ?? null,
                'draft' => $release['isDraft'] ?? false,
                'prerelease' => $release['isPrerelease'] ?? false,
                'created_at' => $release['createdAt'] ?? null,
                'published_at' => $release['publishedAt'] ?? null,
                'assets' => $assets,
            ]);

            if ($normalized !== null) {
                return $normalized;
            }
        }

        return null;
    }

    /**
     * @param array<int, mixed> $releases
     * @return array<string, mixed>|null
     */
    protected function latestNormalizedRestVersion(array $releases): ?array
    {
        foreach ($releases as $release) {
            if (!is_array($release) || ($release['draft'] ?? false)) {
                continue;
            }

            $normalized = $this->normalizeVersion($release);
            if ($normalized !== null) {
                return $normalized;
            }
        }

        return null;
    }

    /**
     * @param array<int, string> $unresolved
     * @param array<int, LatestVersionLookupRequest> $requests
     */
    protected function recordUnresolved(array &$unresolved, array $requests): void
    {
        foreach ($requests as $request) {
            $unresolved[] = $request->key();
        }
    }

    /**
     * @param array<string, string> $failures
     * @param array<int, LatestVersionLookupRequest> $requests
     */
    protected function recordFailure(array &$failures, array $requests, string $message): void
    {
        foreach ($requests as $request) {
            $failures[$request->key()] = $message;

        }
    }
    protected function logLatestVersionsTiming(
        string $stage,
        string $endpoint,
        float $startedAt,
        int $requestedProjectCount,
        int $returnedProjectCount,
        int $requestCount,
        int $failureCount,
    ): void {
        logger()->info('Mod manager timing', [
            'stage' => $stage,
            'request_id' => request()->attributes->get('mmr_timing_request_id'),
            'endpoint' => $endpoint,
            'started_after_ms' => $this->getModManagerTimingElapsedMs($startedAt),
            'finished_after_ms' => $this->getModManagerTimingElapsedMs(),
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'requested_project_count' => $requestedProjectCount,
            'returned_project_count' => $returnedProjectCount,
            'request_count' => $requestCount,
            'pool_size' => $stage === 'github_versions_rest_parallel_request' ? self::REST_POOL_SIZE : null,
            'failure_count' => $failureCount,
        ]);
    }

    protected function getModManagerTimingElapsedMs(?float $timestamp = null): ?int
    {
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
