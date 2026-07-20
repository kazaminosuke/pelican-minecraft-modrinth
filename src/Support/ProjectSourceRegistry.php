<?php

namespace Boy132\MinecraftModrinth\Support;

use App\Models\Server;
use Boy132\MinecraftModrinth\Contracts\ProjectSourceInterface;
use Boy132\MinecraftModrinth\Enums\ModrinthProjectType;
use Boy132\MinecraftModrinth\Enums\ProjectSourceKey;
use Boy132\MinecraftModrinth\Sources\CurseForgeSource;
use Boy132\MinecraftModrinth\Sources\GitHubReleasesSource;
use Boy132\MinecraftModrinth\Sources\HangarSource;
use Boy132\MinecraftModrinth\Sources\ModrinthSource;
use Exception;

class ProjectSourceRegistry
{
    /** @var array<string, ProjectSourceInterface> */
    protected array $sources;

    public function __construct(
        ModrinthSource $modrinth,
        CurseForgeSource $curseForge,
        HangarSource $hangar,
        GitHubReleasesSource $githubReleases,
    ) {
        $this->sources = [
            ProjectSourceKey::Modrinth->value => $modrinth,
            ProjectSourceKey::CurseForge->value => $curseForge,
            ProjectSourceKey::Hangar->value => $hangar,
            ProjectSourceKey::GitHubReleases->value => $githubReleases,
        ];
    }

    /** @return array<int, ProjectSourceInterface> */
    public function all(): array
    {
        return array_values($this->sources);
    }

    public function get(ProjectSourceKey $key): ?ProjectSourceInterface
    {
        return $this->sources[$key->value] ?? null;
    }

    public function getByValue(?string $key): ?ProjectSourceInterface
    {
        return $key !== null ? ($this->sources[$key] ?? null) : null;
    }

    /**
     * Sources enabled for this server, filtered to those supporting the given
     * project type.
     *
     * Modrinth is always the baseline source - unchanged from pre-multi-source
     * behavior, so no existing egg needs to be touched. An egg opts into
     * additional sources purely additively, by adding their ProjectSourceKey
     * value (e.g. "curseforge", "hangar") to its features; doing so never
     * silently removes Modrinth.
     *
     * Does NOT filter by isConfigured() - callers decide how to present an
     * enabled-but-unconfigured source (e.g. a disabled tab with a "configure
     * in plugin settings" hint).
     *
     * @return array<int, ProjectSourceInterface>
     */
    public function availableFor(Server $server, ModrinthProjectType $type): array
    {
        $server->loadMissing('egg');
        $features = $server->egg->features ?? [];

        $enabled = [$this->sources[ProjectSourceKey::Modrinth->value]];

        foreach ([ProjectSourceKey::CurseForge, ProjectSourceKey::Hangar, ProjectSourceKey::GitHubReleases] as $key) {
            if (in_array($key->value, $features, true)) {
                $enabled[] = $this->sources[$key->value];
            }
        }

        return array_values(array_filter(
            $enabled,
            fn (ProjectSourceInterface $source) => $source->supportsProjectType($type)
        ));
    }

    /**
     * Hydrates installed-mod metadata entries (each tagged with a `source`,
     * per Phase 3) with live display data from each entry's actual source.
     * Entries are grouped by source and each source's lookup is batched
     * (chunks of 100 project ids, each chunk cached 30 minutes) so a large
     * modpack with mods from several sources doesn't issue one request per
     * mod. An entry whose source has no live match (removed upstream, or an
     * unimplemented/unrecognized source) falls back to an "unavailable"
     * placeholder built from the stored metadata.
     *
     * @param array<int, array<string, mixed>> $installedMods
     * @return array<int, array<string, mixed>>
     */
    public function hydrateInstalled(array $installedMods): array
    {
        $bySource = [];
        foreach ($installedMods as $mod) {
            $bySource[$mod['source'] ?? ProjectSourceKey::Modrinth->value][] = $mod;
        }

        $results = [];

        foreach ($bySource as $sourceKey => $mods) {
            $source = $this->getByValue($sourceKey);
            $projectsMap = $source ? $this->fetchProjectsMap($source, $sourceKey, $mods) : [];

            foreach ($mods as $mod) {
                $project = $projectsMap[$mod['project_id']] ?? null;

                if ($project === null) {
                    $results[] = $this->unavailableEntry($mod, $sourceKey);

                    continue;
                }

                $project['project_id'] = $mod['project_id'];
                $project['source'] = $sourceKey;

                if (empty($project['author']) && !empty($mod['author'])) {
                    $project['author'] = $mod['author'];
                }

                $results[] = $project;
            }
        }

        return $results;
    }

    /**
     * @param array<int, array<string, mixed>> $mods
     * @return array<string, mixed>
     */
    protected function fetchProjectsMap(ProjectSourceInterface $source, string $sourceKey, array $mods): array
    {
        $projectIds = array_values(array_unique(array_column($mods, 'project_id')));
        $projectsMap = [];

        foreach (array_chunk($projectIds, 100) as $chunk) {
            $cacheKey = "{$sourceKey}_bulk_hydrate:".md5(implode(',', $chunk));

            $chunkMap = cache()->remember($cacheKey, now()->addMinutes(30), function () use ($source, $chunk) {
                try {
                    return $source->getProjectsByIds($chunk);
                } catch (Exception $exception) {
                    report($exception);

                    return [];
                }
            });

            if (is_array($chunkMap)) {
                $projectsMap += $chunkMap;
            }
        }

        return $projectsMap;
    }

    /** @param array<string, mixed> $mod */
    protected function unavailableEntry(array $mod, string $sourceKey): array
    {
        return [
            'project_id' => $mod['project_id'] ?? '',
            'slug' => $mod['project_slug'] ?? '',
            'title' => $mod['project_title'] ?? '',
            'description' => trans('pelican-minecraft-modrinth::strings.page.mod_unavailable'),
            'icon_url' => null,
            'author' => $mod['author'] ?? '',
            'downloads' => 0,
            'date_modified' => $mod['installed_at'] ?? null,
            'project_type' => '',
            'source' => $sourceKey,
            'unavailable' => true,
        ];
    }
}
