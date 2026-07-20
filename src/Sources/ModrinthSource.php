<?php

namespace Boy132\MinecraftModrinth\Sources;

use App\Models\Server;
use Boy132\MinecraftModrinth\Contracts\ProjectSourceInterface;
use Boy132\MinecraftModrinth\Enums\ModrinthProjectType;
use Boy132\MinecraftModrinth\Enums\ProjectSourceKey;
use Boy132\MinecraftModrinth\Support\MinecraftVersionResolver;
use Exception;
use Illuminate\Support\Facades\Http;

class ModrinthSource implements ProjectSourceInterface
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

    public function supportsProjectType(ModrinthProjectType $type): bool
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
    public function search(Server $server, ModrinthProjectType $type, int $page = 1, ?string $search = null): array
    {
        $minecraftLoader = $type->getModrinthLoader($server);
        $projectType = $type->value;
        $minecraftVersion = MinecraftVersionResolver::resolve($server);

        if ($type === ModrinthProjectType::Datapack) {
            $facets = "[[\"versions:$minecraftVersion\"],[\"project_type:{$projectType}\"]]";
        } else {
            if (!$minecraftLoader) {
                return [
                    'hits' => [],
                    'total_hits' => 0,
                ];
            }

            $facets = "[[\"categories:$minecraftLoader\"],[\"versions:$minecraftVersion\"],[\"project_type:{$projectType}\"]]";
        }

        $data = [
            'offset' => ($page - 1) * 20,
            'limit' => 20,
            'facets' => $facets,
        ];

        $key = "modrinth_projects:{$projectType}:$minecraftVersion:" . ($minecraftLoader ?? 'datapack') . ":$page";

        if ($search) {
            $data['query'] = $search;

            $key .= ":$search";
        }

        return cache()->remember($key, now()->addMinutes(30), function () use ($data) {
            try {
                return Http::asJson()
                    ->timeout(5)
                    ->connectTimeout(5)
                    ->throw()
                    ->get(self::BASE_URL.'/search', $data)
                    ->json();
            } catch (Exception $exception) {
                report($exception);

                return [
                    'hits' => [],
                    'total_hits' => 0,
                ];
            }
        });
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
    public function getVersions(string $projectId, Server $server, ModrinthProjectType $type): array
    {
        $minecraftLoader = $type->getModrinthLoader($server);

        if (!$minecraftLoader) {
            return [];
        }

        $minecraftVersion = MinecraftVersionResolver::resolve($server);

        $data = [
            'game_versions' => "[\"$minecraftVersion\"]",
            'loaders' => "[\"$minecraftLoader\"]",
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
                    $map[$project['id']] = $project;
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
}
