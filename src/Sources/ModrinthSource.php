<?php

namespace Kazaminosuke\ModManager\Sources;

use App\Models\Server;
use Exception;
use Illuminate\Support\Facades\Http;
use Kazaminosuke\ModManager\Contracts\BatchLatestVersionSourceInterface;
use Kazaminosuke\ModManager\Contracts\ProjectSourceInterface;
use Kazaminosuke\ModManager\Enums\ProjectSourceKey;
use Kazaminosuke\ModManager\Enums\ProjectType;
use Kazaminosuke\ModManager\Support\LatestVersionLookupRequest;
use Kazaminosuke\ModManager\Support\LatestVersionLookupResult;
use Kazaminosuke\ModManager\Support\MinecraftVersionResolver;

class ModrinthSource implements BatchLatestVersionSourceInterface, ProjectSourceInterface
{
    protected const BASE_URL = 'https://api.modrinth.com/v2';

    public function getKey(): ProjectSourceKey
    {
        return ProjectSourceKey::Modrinth;
    }

    public function getLabel(): string
    {
        return 'Modrinth';
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
        return true;
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
        return 'sha512';
    }

    public function supportsDirectIdentifier(): bool
    {
        return true;
    }

    /** @return array{hits: array<int, array<string, mixed>>, total_hits: int} */
    public function search(Server $server, ProjectType $type, int $page = 1, ?string $search = null, array $filters = []): array
    {
        $minecraftLoader = $type->getLoaderSlug($server);
        $projectType = $type->value;
        $minecraftVersion = MinecraftVersionResolver::resolve($server);

        if ($type === ProjectType::Datapack) {
            $facetGroups = [["versions:$minecraftVersion"], ["project_type:{$projectType}"]];
        } else {
            if (!$minecraftLoader) {
                return ['hits' => [], 'total_hits' => 0];
            }

            $facetGroups = [["categories:$minecraftLoader"], ["versions:$minecraftVersion"], ["project_type:{$projectType}"]];
        }

        if (!empty($filters['category'])) {
            $facetGroups[] = ['categories:'.$filters['category']];
        }
        if (!empty($filters['environment'])) {
            $facetGroups[] = $filters['environment'] === 'server'
                ? ['server_side:required', 'server_side:optional']
                : ['server_side:unsupported'];
        }

        $data = [
            'offset' => ($page - 1) * 20,
            'limit' => 20,
            'facets' => json_encode($facetGroups),
            'index' => match ($filters['sort'] ?? 'downloads') {
                'updated' => 'updated',
                'popularity' => 'relevance',
                default => 'downloads',
            },
        ];

        if ($search) {
            $data['query'] = $search;
        }

        $cacheKey = 'modrinth_search:v2:'.md5(json_encode($data));
        $cached = cache()->get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $debugTiming = (bool) config('pelican-minecraft-modrinth.debug_timing', false);
        $startedAt = $debugTiming ? microtime(true) : 0.0;
        $responseBytes = null;

        try {
            $response = Http::asJson()
                ->timeout(2)
                ->connectTimeout(1)
                ->throw()
                ->get(self::BASE_URL.'/search', $data);
            if ($debugTiming) {
                $responseBytes = strlen($response->body());
            }

            $payload = $response->json();

            if (!is_array($payload)) {
                throw new Exception('Invalid Modrinth search response');
            }

            $result = [
                'hits' => collect($payload['hits'] ?? [])
                    ->filter(fn ($project) => is_array($project))
                    ->map(fn (array $project) => $this->normalizeSearchProject($project))
                    ->values()
                    ->all(),
                'total_hits' => (int) ($payload['total_hits'] ?? 0),
            ];

            cache()->put($cacheKey, $result, now()->addMinutes(30));

            return $result;
        } catch (Exception $exception) {
            report($exception);

            return ['hits' => [], 'total_hits' => 0];
        } finally {
            if ($debugTiming) {
                logger()->debug('Catalog search API timing', [
                    'source' => 'modrinth',
                    'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                    'response_bytes' => $responseBytes,
                ]);
            }
        }
    }

    /** @param array<string, mixed> $project */
    protected function normalizeSearchProject(array $project): array
    {
        return [
            'project_id' => (string) ($project['project_id'] ?? $project['id'] ?? ''),
            'slug' => $project['slug'] ?? '',
            'title' => $project['title'] ?? '',
            'description' => $project['description'] ?? '',
            'icon_url' => $project['icon_url'] ?? null,
            'author' => $project['author'] ?? null,
            'downloads' => (int) ($project['downloads'] ?? 0),
            'date_modified' => $project['date_modified'] ?? $project['updated'] ?? null,
            'project_type' => $project['project_type'] ?? '',
        ];
    }

    /**
     * @param array<int, array{project_id: string, project_slug: string, project_title: string, version_id: string, version_number: string, filename: string, installed_at: string, author?: string}> $installedMods
     * @return array<int, array<string, mixed>>
     */
    public function getInstalledModsFromModrinth(array $installedMods, int $page = 1): array
    {
        if (empty($installedMods)) {
            return [];
        }

        $projectIds = collect($installedMods)->pluck('project_id')->unique()->values()->all();

        $perPage = 20;
        $offset = ($page - 1) * $perPage;
        $pageIds = array_slice($projectIds, $offset, $perPage);

        if (empty($pageIds)) {
            return [];
        }

        $idsParam = '["'.implode('","', $pageIds).'"]';
        $key = 'modrinth_bulk:'.md5($idsParam);

        $modrinthProjects = cache()->remember($key, now()->addMinutes(30), function () use ($idsParam) {
            try {
                return Http::asJson()
                    ->timeout(10)
                    ->connectTimeout(5)
                    ->throw()
                    ->get(self::BASE_URL.'/projects', [
                        'ids' => $idsParam,
                    ])
                    ->json();
            } catch (Exception $exception) {
                report($exception);

                return [];
            }
        });

        if (!is_array($modrinthProjects)) {
            $modrinthProjects = [];
        }

        $modrinthMap = [];
        foreach ($modrinthProjects as $project) {
            if (isset($project['id'])) {
                $modrinthMap[$project['id']] = $project;
            }
        }

        $results = [];
        foreach ($pageIds as $projectId) {
            $installedMod = null;
            foreach ($installedMods as $mod) {
                if ($mod['project_id'] === $projectId) {
                    $installedMod = $mod;
                    break;
                }
            }

            if (!$installedMod) {
                continue;
            }

            if (isset($modrinthMap[$projectId])) {
                $project = $modrinthMap[$projectId];
                $project['project_id'] = $project['id'];
                if (isset($project['updated']) && !isset($project['date_modified'])) {
                    $project['date_modified'] = $project['updated'];
                }
                if (isset($installedMod['author']) && !isset($project['author'])) {
                    $project['author'] = $installedMod['author'];
                }
                $results[] = $project;
            } else {
                $results[] = [
                    'project_id' => $installedMod['project_id'],
                    'slug' => $installedMod['project_slug'],
                    'title' => $installedMod['project_title'],
                    'description' => trans('pelican-minecraft-modrinth::strings.page.mod_unavailable'),
                    'icon_url' => null,
                    'author' => $installedMod['author'] ?? '',
                    'downloads' => 0,
                    'date_modified' => $installedMod['installed_at'],
                    'project_type' => '',
                    'unavailable' => true,
                ];
            }
        }

        return $results;
    }

    /** @return array<int, mixed> */
    public function getVersions(string $projectId, Server $server, ProjectType $type): array
    {
        $minecraftLoader = $type->getLoaderSlug($server);

        if (!$minecraftLoader) {
            return [];
        }

        $minecraftVersion = MinecraftVersionResolver::resolve($server);

        $data = [
            'game_versions' => "[\"$minecraftVersion\"]",
            'loaders' => "[\"$minecraftLoader\"]",
            'include_changelog' => 'false',
        ];

        return cache()->remember("modrinth_versions:$projectId:$minecraftVersion:$minecraftLoader", now()->addMinutes(30), function () use ($projectId, $data) {
            try {
                $versions = Http::asJson()
                    ->timeout(5)
                    ->connectTimeout(5)
                    ->throw()
                    ->get(self::BASE_URL."/project/$projectId/version", $data)
                    ->json();

                if (!empty($versions) && is_array($versions) && isset($versions[0]['date_published'])) {
                    usort($versions, function ($a, $b) {
                        return strcmp($b['date_published'] ?? '', $a['date_published'] ?? '');
                    });
                }

                return $versions;
            } catch (Exception $exception) {
                report($exception);

                return [];
            }
        });
    }

    /**
     * @param array<int, LatestVersionLookupRequest> $requests
     */
    public function lookupLatestVersions(
        array $requests,
        Server $server,
        ProjectType $type,
    ): LatestVersionLookupResult {
        $debugTiming = (bool) config('pelican-minecraft-modrinth.debug_timing', false);
        $startedAt = $debugTiming ? microtime(true) : 0.0;
        $validRequests = array_values(array_filter(
            $requests,
            fn ($request) => $request instanceof LatestVersionLookupRequest,
        ));

        if ($validRequests === []) {
            return LatestVersionLookupResult::empty();
        }

        $minecraftLoader = $type->getLoaderSlug($server);
        if (!$minecraftLoader) {
            return LatestVersionLookupResult::failed($validRequests, 'No compatible Modrinth loader is configured.');
        }

        $minecraftVersion = MinecraftVersionResolver::resolve($server);
        $versionsByKey = [];
        $unresolvedKeys = [];
        $failuresByKey = [];
        $pendingByHash = [];
        $cacheKeysByHash = [];

        foreach ($validRequests as $request) {
            $sha512 = $request->hash('sha512');

            if ($sha512 === null) {
                $unresolvedKeys[] = $request->key();

                continue;
            }

            $cacheKey = 'modrinth_latest:'.md5($sha512.'|'.$minecraftVersion.'|'.$minecraftLoader);
            $cached = cache()->get($cacheKey);

            if (is_array($cached) && $cached !== []) {
                $versionsByKey[$request->key()] = $cached;

                continue;
            }

            $pendingByHash[$sha512][] = $request;
            $cacheKeysByHash[$sha512] = $cacheKey;
        }

        $requestStartedAt = null;
        $returnedHashCount = 0;

        if ($pendingByHash !== []) {
            $requestStartedAt = $debugTiming ? microtime(true) : 0.0;

            try {
                $payload = Http::asJson()
                    ->timeout(10)
                    ->connectTimeout(5)
                    ->throw()
                    ->post(self::BASE_URL.'/version_files/update', [
                        'hashes' => array_keys($pendingByHash),
                        'algorithm' => 'sha512',
                        'loaders' => [$minecraftLoader],
                        'game_versions' => [$minecraftVersion],
                    ])
                    ->json();

                if (!is_array($payload)) {
                    throw new Exception('Invalid Modrinth bulk latest-version response.');
                }

                $returnedHashCount = count($payload);

                foreach ($pendingByHash as $hash => $hashRequests) {
                    $version = $payload[$hash] ?? null;

                    if (is_array($version) && $version !== []) {
                        cache()->put($cacheKeysByHash[$hash], $version, now()->addMinutes(30));

                        foreach ($hashRequests as $request) {
                            $versionsByKey[$request->key()] = $version;
                        }
                    } else {
                        foreach ($hashRequests as $request) {
                            $unresolvedKeys[] = $request->key();
                        }
                    }
                }
            } catch (Exception $exception) {
                report($exception);

                foreach ($pendingByHash as $hashRequests) {
                    foreach ($hashRequests as $request) {
                        $failuresByKey[$request->key()] = $exception->getMessage();
                    }
                }
            } finally {
                if ($debugTiming) {
                    logger()->info('Mod manager timing', [
                        'stage' => 'modrinth_versions_bulk_request',
                        'request_id' => request()->attributes->get('mmr_timing_request_id'),
                        'endpoint' => '/version_files/update',
                        'started_after_ms' => $this->getModManagerTimingElapsedMs($requestStartedAt),
                        'finished_after_ms' => $this->getModManagerTimingElapsedMs(),
                        'duration_ms' => (int) round((microtime(true) - $requestStartedAt) * 1000),
                        'requested_hash_count' => count($pendingByHash),
                        'returned_hash_count' => $returnedHashCount,
                    ]);
                }
            }
        }

        if ($debugTiming) {
            logger()->info('Mod manager timing', [
                'stage' => 'modrinth_latest_lookup_batch',
                'request_id' => request()->attributes->get('mmr_timing_request_id'),
                'started_after_ms' => $this->getModManagerTimingElapsedMs($startedAt),
                'finished_after_ms' => $this->getModManagerTimingElapsedMs(),
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'requested_project_count' => count($validRequests),
                'resolved_project_count' => count($versionsByKey),
                'unresolved_project_count' => count($unresolvedKeys),
                'failed_project_count' => count($failuresByKey),
            ]);
        }

        return new LatestVersionLookupResult(
            versionsByKey: $versionsByKey,
            unresolvedKeys: $unresolvedKeys,
            failuresByKey: $failuresByKey,
        );
    }

    /**
     * @param array<string, string> $hashesByFilename [filename => sha512hash]
     * @return array<string, mixed> [sha512hash => versionData]
     */
    public function findVersionsByHash(array $hashesByFilename): array
    {
        if (empty($hashesByFilename)) {
            return [];
        }

        $hashes = array_values($hashesByFilename);

        try {
            $result = Http::asJson()
                ->timeout(10)
                ->connectTimeout(5)
                ->throw()
                ->post(self::BASE_URL.'/version_files', [
                    'hashes' => $hashes,
                    'algorithm' => 'sha512',
                ])
                ->json();

            return is_array($result) ? $result : [];
        } catch (Exception $exception) {
            report($exception);

            return [];
        }
    }

    /**
     * @param array<int, string> $projectIds
     * @return array<string, mixed> [projectId => projectData]
     *
     * @throws Exception
     */
    public function getProjectsByIds(array $projectIds): array
    {
        if (empty($projectIds)) {
            return [];
        }

        $projectIds = array_values(array_unique($projectIds));
        $idsParam = '["'.implode('","', $projectIds).'"]';

        try {
            $projects = Http::asJson()
                ->timeout(10)
                ->connectTimeout(5)
                ->throw()
                ->get(self::BASE_URL.'/projects', [
                    'ids' => $idsParam,
                ])
                ->json();

            if (!is_array($projects)) {
                return [];
            }

            $map = [];
            foreach ($projects as $project) {
                if (isset($project['id'])) {
                    $map[$project['id']] = [
                        'project_id' => (string) $project['id'],
                        'slug' => $project['slug'] ?? '',
                        'title' => $project['title'] ?? '',
                        'description' => $project['description'] ?? '',
                        'icon_url' => $project['icon_url'] ?? null,
                        'author' => null,
                        'downloads' => (int) ($project['downloads'] ?? 0),
                        'date_modified' => $project['updated'] ?? null,
                        'project_type' => $project['project_type'] ?? '',
                    ];
                }
            }

            return $map;
        } catch (Exception $exception) {
            report($exception);

            throw new Exception('Modrinth projects lookup failed', previous: $exception);
        }
    }

    /** @return array<string, mixed>|null */
    public function getProject(string $projectId): ?array
    {
        return cache()->remember("modrinth_project:$projectId", now()->addMinutes(30), function () use ($projectId) {
            try {
                $project = Http::asJson()
                    ->timeout(5)
                    ->connectTimeout(5)
                    ->throw()
                    ->get(self::BASE_URL."/project/$projectId")
                    ->json();

                return is_array($project) ? $project : null;
            } catch (Exception $exception) {
                report($exception);

                return null;
            }
        });
    }

    /** @return array<string, mixed>|null */
    public function resolveProjectByIdentifier(string $identifier): ?array
    {
        return $this->getProject($identifier);
    }

    public function resolveAuthor(?array $project, array $versionData): ?string
    {
        if (is_string($project['author'] ?? null) && $project['author'] !== '') {
            return $project['author'];
        }

        if (is_string($project['team'] ?? null) && $project['team'] !== '') {
            $teamUsername = $this->fetchTeamPrimaryUsername($project['team']);
            if ($teamUsername !== null) {
                return $teamUsername;
            }
        }

        if (is_string($versionData['author_id'] ?? null) && $versionData['author_id'] !== '') {
            return $this->fetchUsernameByUserId($versionData['author_id']);
        }

        return null;
    }

    protected function fetchTeamPrimaryUsername(string $teamId): ?string
    {
        $cacheKey = 'modrinth_team_primary_user:'.$teamId;

        return cache()->remember($cacheKey, now()->addMinutes(30), function () use ($teamId) {
            try {
                $members = Http::asJson()
                    ->timeout(10)
                    ->connectTimeout(5)
                    ->throw()
                    ->get(self::BASE_URL."/team/{$teamId}/members")
                    ->json();

                if (!is_array($members) || empty($members)) {
                    return null;
                }

                foreach ($members as $member) {
                    $username = $member['user']['username'] ?? null;
                    if (is_string($username) && $username !== '') {
                        return $username;
                    }
                }

                return null;
            } catch (Exception $exception) {
                report($exception);

                return null;
            }
        });
    }

    protected function fetchUsernameByUserId(string $userId): ?string
    {
        $cacheKey = 'modrinth_user_username:'.$userId;

        return cache()->remember($cacheKey, now()->addMinutes(30), function () use ($userId) {
            try {
                $user = Http::asJson()
                    ->timeout(10)
                    ->connectTimeout(5)
                    ->throw()
                    ->get(self::BASE_URL."/user/{$userId}")
                    ->json();

                $username = $user['username'] ?? null;

                return is_string($username) && $username !== '' ? $username : null;
            } catch (Exception $exception) {
                report($exception);

                return null;
            }
        });
    }

    protected function getModManagerTimingElapsedMs(?float $timestamp = null): ?int
    {
        if (!(bool) config('pelican-minecraft-modrinth.debug_timing', false)) {
            return null;
        }

        $startedAt = request()->attributes->get('mmr_timing_started_at');

        if (!is_float($startedAt)) {
            return null;
        }

        return (int) round((($timestamp ?? microtime(true)) - $startedAt) * 1000);
    }
}
