<?php

namespace Boy132\MinecraftModrinth\Filament\Server\Pages;

use App\Filament\Server\Resources\Files\Pages\ListFiles;
use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use App\Traits\Filament\BlockAccessInConflict;
use Boy132\MinecraftModrinth\Contracts\ProjectSourceInterface;
use Boy132\MinecraftModrinth\Enums\MinecraftLoader;
use Boy132\MinecraftModrinth\Enums\ModrinthProjectType;
use Boy132\MinecraftModrinth\Enums\ProjectSourceKey;
use Boy132\MinecraftModrinth\Facades\MinecraftModrinth;
use Boy132\MinecraftModrinth\Support\ProjectSourceRegistry;
use Exception;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Resources\Concerns\HasTabs;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Enums\TextSize;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MinecraftModrinthProjectPage extends Page implements HasTable
{
    use BlockAccessInConflict;
    use HasTabs {
        HasTabs::updatedActiveTab as protected baseUpdatedActiveTab;
    }
    use InteractsWithTable;

    /** @var array<int, array{source: string, project_id: string, project_slug: string, project_title: string, version_id: string, version_number: string, filename: string, installed_at: string, author?: string}>|null */
    protected ?array $installedModsMetadata = null;

    /** @var array<string, array<int, mixed>> Cache for version data by "source:project_id" */
    protected array $versionsCache = [];

    /** @var array<int, ProjectSourceInterface>|null */
    protected ?array $availableSources = null;

    /** @var array<string> */
    public array $unknownFiles = [];

    protected ?string $datapackWorldName = null;

    protected static string|\BackedEnum|null $navigationIcon = 'tabler-packages';

    protected static ?string $slug = 'mod-manager';

    public static function getNavigationSort(): ?int
    {
        return (int) env('MINECRAFT_MODRINTH_NAV_SORT', 11);
    }

    protected static function detectProjectType(Server $server): ?ModrinthProjectType
    {
        return ModrinthProjectType::fromServer($server);
    }

    public static function canAccess(): bool
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        return parent::canAccess() && static::detectProjectType($server);
    }

    public static function getNavigationLabel(): string
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        $type = static::detectProjectType($server);

        return $type?->getLabel() ?? 'Modrinth';
    }

    public static function getModelLabel(): string
    {
        return static::getNavigationLabel();
    }

    public static function getPluralModelLabel(): string
    {
        return static::getNavigationLabel();
    }

    public function getTitle(): string
    {
        return static::getNavigationLabel();
    }

    public function mount(): void
    {
        $this->loadDefaultActiveTab();
        $this->queueTableHeightRecalculation();
    }

    public function updatedActiveTab(?string $activeTab): void
    {
        // HasTabs::updatedActiveTab() (aliased above) already resets the table's
        // page - each tab (source or "installed") paginates its own independent
        // result set, so a page number from the previous tab has no meaning here
        // (e.g. leaving Modrinth on page 909 and switching to a CurseForge tab
        // with far fewer results) - plus resets the column manager state. It was
        // being silently dropped by this method overriding it without calling it.
        $this->baseUpdatedActiveTab();
        $this->queueTableHeightRecalculation();
        $this->queueHeaderScroll();

        if ($activeTab !== 'installed') {
            return;
        }

        /** @var Server $server */
        $server = Filament::getTenant();

        $projectType = static::detectProjectType($server);
        $scanCacheKey = MinecraftModrinth::getHashScanCacheKey($server, $projectType);
        if (Cache::has($scanCacheKey)) {
            return;
        }

        /** @var DaemonFileRepository $fileRepository */
        $fileRepository = app(DaemonFileRepository::class);
        if (!$this->hasScanCandidates($server, $fileRepository)) {
            return;
        }

        $scanInProgressKey = match ($projectType) {
            ModrinthProjectType::Plugin => 'pelican-minecraft-modrinth::strings.notifications.scan_in_progress_plugins',
            ModrinthProjectType::Datapack => 'pelican-minecraft-modrinth::strings.notifications.scan_in_progress_datapacks',
            default => 'pelican-minecraft-modrinth::strings.notifications.scan_in_progress_mods',
        };

        Notification::make()
            ->title(trans($scanInProgressKey))
            ->info()

            ->send();
    }
    /**
     * Resize the table after Livewire has finished morphing its contents.
     *
     * The table wrapper remains the same DOM element when only the active tab
     * changes, while Filament replaces the table contents below it. Alpine's
     * x-init consequently does not run again, so this needs to be a Livewire
     * post-update effect instead.
     */
    protected function queueTableHeightRecalculation(): void
    {
        $this->js(<<<'JS'
            (() => {
                const resizeTables = () => {
                    document.querySelectorAll('.mmr-table-scroll-ctn .fi-pagination-items').forEach((items) => {
                        const paginationItems = Array.from(items.children);
                        const previous = paginationItems.find((item) => item.matches('.fi-pagination-item[rel="prev"]'));

                        if (previous) {
                            window.mmrPaginationPreviousWidth = previous.getBoundingClientRect().width;

                            return;
                        }

                        if (items.dataset.mmrPaginationPreviousSpace === 'true') {
                            return;
                        }

                        const next = paginationItems.find((item) => item.matches('.fi-pagination-item[rel="next"]'));

                        if (!next) {
                            return;
                        }

                        const width = window.mmrPaginationPreviousWidth ?? next.getBoundingClientRect().width;

                        if (width === 0) {
                            return;
                        }

                        items.style.marginInlineStart = `${width}px`;
                        items.dataset.mmrPaginationPreviousSpace = 'true';
                    });

                    document.querySelectorAll('.mmr-table-scroll-ctn .fi-ta-content-ctn').forEach((ctn) => {
                        const documentBottom = Math.max(document.body.scrollHeight, document.documentElement.scrollHeight);
                        const viewportBottom = window.scrollY + window.innerHeight;
                        const overflow = Math.max(documentBottom - viewportBottom, 0);

                        if (overflow === 0) return;

                        const available = ctn.getBoundingClientRect().height - overflow - 24;
                        ctn.style.maxHeight = Math.max(available, 240) + 'px';
                    });
                };

                if (!window.mmrResizeTables) {
                    window.mmrResizeTables = resizeTables;
                    window.addEventListener('resize', window.mmrResizeTables);
                }

                requestAnimationFrame(() => {
                    window.mmrResizeTables();
                    requestAnimationFrame(window.mmrResizeTables);
                });
            })()
            JS);
    }


    public function updatedPaginators($page, $pageName): void
    {
        if ($pageName !== $this->getTablePaginationPageName()) {
            return;
        }

        $this->queueTableHeightRecalculation();
        $this->queueHeaderScroll();
    }
    /**
     * Scroll a table page change to Filament's page title after layout settles.
     */
    protected function queueHeaderScroll(): void
    {
        $this->js(<<<'JS'
            if (window.mmrHeaderScrollFrame) {
                cancelAnimationFrame(window.mmrHeaderScrollFrame);
            }

            window.mmrHeaderScrollFrame = requestAnimationFrame(() => {
                window.mmrHeaderScrollFrame = requestAnimationFrame(() => {
                    window.mmrHeaderScrollFrame = null;

                    // The standard Filament page header (which contains this page's
                    // title) is rendered before the schema slot. Keep the schema
                    // header as a fallback for panels with a customized page view.
                    const header = document.querySelector('.fi-page .fi-header') ?? document.querySelector('.mmr-page-header');
                    if (!header) return;

                    const topbarHeight = document.querySelector('.fi-topbar')?.getBoundingClientRect().height ?? 0;
                    const top = window.scrollY + header.getBoundingClientRect().top - topbarHeight - 16;

                    window.scrollTo({ top: Math.max(top, 0), behavior: 'smooth' });
                });
            });
            JS);
    }


    protected function hasScanCandidates(Server $server, DaemonFileRepository $fileRepository): bool
    {
        $type = static::detectProjectType($server);
        if (!$type) {
            return false;
        }

        try {
            $directoryContents = $fileRepository->setServer($server)->getDirectory(MinecraftModrinth::getProjectFolder($server, $fileRepository, $type));
        } catch (Exception $exception) {
            report($exception);

            return false;
        }

        $extension = $type->getFileExtension();

        $diskFiles = collect($directoryContents)
            ->filter(fn ($item) => isset($item['name']) && str_ends_with(strtolower($item['name']), $extension))
            ->pluck('name')
            ->map(fn ($name) => strtolower($name))
            ->values()
            ->toArray();

        $knownFilenames = array_map('strtolower', MinecraftModrinth::getInstalledMods($server, $fileRepository, $type));

        foreach ($diskFiles as $filename) {
            if (!in_array($filename, $knownFilenames, true)) {
                return true;
            }
        }

        foreach ($knownFilenames as $filename) {
            if (!in_array($filename, $diskFiles, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Sources enabled for this egg (via feature flags) that support the
     * current page's project type. An egg with no source feature flags set
     * (every egg before this feature existed) resolves to Modrinth only.
     *
     * @return array<int, ProjectSourceInterface>
     */
    protected function getAvailableSources(): array
    {
        if ($this->availableSources !== null) {
            return $this->availableSources;
        }

        /** @var Server $server */
        $server = Filament::getTenant();
        $type = static::detectProjectType($server);

        return $this->availableSources = $type
            ? app(ProjectSourceRegistry::class)->availableFor($server, $type)
            : [];
    }

    /**
     * The source backing the currently active tab. When only one source is
     * available (the common, backward-compatible case), it's used regardless
     * of the tab key, since the tab is the generic "all" tab rather than a
     * per-source one.
     */
    protected function getCurrentSource(): ?ProjectSourceInterface
    {
        $sources = $this->getAvailableSources();

        if (count($sources) <= 1) {
            return $sources[0] ?? null;
        }

        foreach ($sources as $source) {
            if ($source->getKey()->value === $this->activeTab) {
                return $source;
            }
        }

        return null;
    }

    protected function getSourceLabel(?string $sourceKey): string
    {
        if (!$sourceKey) {
            return '';
        }

        $key = ProjectSourceKey::tryFrom($sourceKey);
        $source = $key ? app(ProjectSourceRegistry::class)->get($key) : null;

        return $source?->getLabel() ?? ucfirst($sourceKey);
    }

    /**
     * One tab per available source (when more than one is enabled for this
     * egg), each showing a "needs configuration" badge if isConfigured() is
     * false, plus the "Installed" tab. When only Modrinth is available (the
     * default for every egg that hasn't opted into extra sources), this
     * collapses back to the original "All" / "Installed" tabs unchanged.
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        $sources = $this->getAvailableSources();
        $tabs = [];

        if (count($sources) <= 1) {
            $tabs['all'] = Tab::make(trans('pelican-minecraft-modrinth::strings.page.view_all'));
        } else {
            foreach ($sources as $source) {
                $tab = Tab::make($source->getLabel());

                if (!$source->isConfigured()) {
                    $tab = $tab->badge('!')
                        ->badgeColor('warning')
                        ->badgeTooltip(trans('pelican-minecraft-modrinth::strings.page.source_not_configured'));
                }

                $tabs[$source->getKey()->value] = $tab;
            }
        }

        $tabs['installed'] = Tab::make(trans('pelican-minecraft-modrinth::strings.page.view_installed'));

        return $tabs;
    }

    /** @return array<int, array{source: string, project_id: string, project_slug: string, project_title: string, version_id: string, version_number: string, filename: string, installed_at: string}> */
    protected function getInstalledModsMetadata(): array
    {
        if ($this->installedModsMetadata === null) {
            /** @var Server $server */
            $server = Filament::getTenant();
            /** @var DaemonFileRepository $fileRepository */
            $fileRepository = app(DaemonFileRepository::class);

            $this->installedModsMetadata = MinecraftModrinth::getInstalledModsMetadata($server, $fileRepository, static::detectProjectType($server));
        }

        return $this->installedModsMetadata;
    }

    /** @return array{source: string, project_id: string, project_slug: string, project_title: string, version_id: string, version_number: string, filename: string, installed_at: string, author?: string}|null */
    protected function getInstalledMod(string $projectId, string $sourceKey = ''): ?array
    {
        $sourceKey = $sourceKey !== '' ? $sourceKey : ProjectSourceKey::Modrinth->value;
        $installedMods = $this->getInstalledModsMetadata();

        foreach ($installedMods as $mod) {
            if ($mod['project_id'] === $projectId && ($mod['source'] ?? ProjectSourceKey::Modrinth->value) === $sourceKey) {
                return $mod;
            }
        }

        return null;
    }

    /** @return array<int, mixed> */
    protected function getCachedVersions(string $projectId, string $sourceKey): array
    {
        $cacheIndex = "$sourceKey:$projectId";

        if (!isset($this->versionsCache[$cacheIndex])) {
            /** @var Server $server */
            $server = Filament::getTenant();
            $type = static::detectProjectType($server);
            $source = app(ProjectSourceRegistry::class)->get(ProjectSourceKey::tryFrom($sourceKey) ?? ProjectSourceKey::Modrinth);

            $this->versionsCache[$cacheIndex] = ($source && $type) ? $source->getVersions($projectId, $server, $type) : [];
        }

        return $this->versionsCache[$cacheIndex];
    }

    protected function getCachedDatapackWorldName(Server $server, DaemonFileRepository $fileRepository): string
    {
        if ($this->datapackWorldName === null) {
            $this->datapackWorldName = MinecraftModrinth::getDatapackWorldName($server, $fileRepository);
        }

        return $this->datapackWorldName;
    }

    /**
     * @param array<int, array{primary: bool, filename: string, url: string}> $files
     * @return array{primary: bool, filename: string, url: string}|null
     */
    protected function getPrimaryFile(array $files): ?array
    {
        foreach ($files as $file) {
            if (!empty($file['primary'])) {
                return $file;
            }
        }

        return null;
    }

    /**
     * @throws Exception
     */
    protected function validateFilename(string $filename): string
    {
        if ($filename === '' || $filename === '.' || str_contains($filename, "\0") || str_contains($filename, '..') || str_contains($filename, '/') || str_contains($filename, '\\')) {
            throw new Exception('Invalid filename: potential path traversal detected');
        }

        return basename($filename);
    }

    /** @param array<string, mixed> $record */
    protected function getExternalProjectUrl(array $record): ?string
    {
        $sourceKey = $record['source'] ?? null;
        $slug = $record['slug'] ?? null;

        if (!$sourceKey || !$slug) {
            return null;
        }

        /** @var Server $server */
        $server = Filament::getTenant();
        $type = static::detectProjectType($server);
        $projectType = $type?->value ?? ($record['project_type'] ?? 'mod');

        return match ($sourceKey) {
            ProjectSourceKey::Modrinth->value => "https://modrinth.com/{$projectType}/{$slug}",
            ProjectSourceKey::CurseForge->value => 'https://www.curseforge.com/minecraft/'.($projectType === 'plugin' ? 'bukkit-plugins' : 'mc-mods')."/{$slug}",
            ProjectSourceKey::Hangar->value => empty($record['author']) ? null : "https://hangar.papermc.io/{$record['author']}/{$slug}",
            ProjectSourceKey::GitHubReleases->value => "https://github.com/{$slug}",
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $record
     * @param array<string, mixed> $versionData
     * @param array<string, mixed> $primaryFile
     * @param array<string, mixed>|null $installedMod
     *
     * @throws Exception
     */
    private function performInstallOrUpdate(
        Server $server,
        DaemonFileRepository $fileRepository,
        array $record,
        array $versionData,
        array $primaryFile,
        ?array $installedMod = null
    ): void {
        $safeNewFilename = $this->validateFilename($primaryFile['filename']);
        $oldFilename = $installedMod ? $this->validateFilename($installedMod['filename']) : null;

        $type = static::detectProjectType($server);
        if (!$type) {
            throw new Exception('Server does not support Modrinth mods or plugins');
        }

        $sourceKey = ProjectSourceKey::tryFrom($record['source'] ?? '') ?? ProjectSourceKey::Modrinth;

        $folder = MinecraftModrinth::getProjectFolder($server, $fileRepository, $type);

        $fileRepository->setServer($server)->pull($primaryFile['url'], $folder);

        $saved = MinecraftModrinth::saveModMetadata(
            $server,
            $fileRepository,
            $record['project_id'],
            $record['slug'],
            $record['title'],
            $versionData['id'],
            $versionData['version_number'],
            $safeNewFilename,
            $record['author'] ?? null,
            $type,
            $sourceKey
        );

        if (!$saved) {
            try {
                Http::daemon($server->node)
                    ->post("/api/servers/{$server->uuid}/files/delete", [
                        'root' => '/',
                        'files' => [$folder.'/'.$safeNewFilename],
                    ])
                    ->throw();
            } catch (Exception $rollbackException) {
                report($rollbackException);
            }

            throw new Exception('Failed to save mod metadata');
        }

        if ($oldFilename && $oldFilename !== $safeNewFilename) {
            try {
                Http::daemon($server->node)
                    ->post("/api/servers/{$server->uuid}/files/delete", [
                        'root' => '/',
                        'files' => [$folder.'/'.$oldFilename],
                    ])
                    ->throw();
            } catch (Exception $deleteException) {
                try {
                    Http::daemon($server->node)
                        ->post("/api/servers/{$server->uuid}/files/delete", [
                            'root' => '/',
                            'files' => [$folder.'/'.$safeNewFilename],
                        ])
                        ->throw();
                } catch (Exception $rollbackException) {
                    report($rollbackException);
                }

                if ($installedMod && !MinecraftModrinth::saveModMetadata(
                    $server,
                    $fileRepository,
                    $record['project_id'],
                    $installedMod['project_slug'],
                    $installedMod['project_title'],
                    $installedMod['version_id'],
                    $installedMod['version_number'],
                    $oldFilename,
                    $installedMod['author'] ?? null,
                    $type,
                    ProjectSourceKey::tryFrom($installedMod['source'] ?? '') ?? $sourceKey
                )) {
                    report(new Exception('Failed to restore old mod metadata during rollback'));
                }

                throw $deleteException;
            }
        }

        Cache::forget(MinecraftModrinth::getHashScanCacheKey($server, $type));
        $this->unknownFiles = array_values(
            array_filter($this->unknownFiles, fn (string $filename) => strtolower($filename) !== strtolower($safeNewFilename))
        );
    }

    /**
     * @return array{updated: int, failed: int}
     */
    private function performBulkUpdate(Server $server, DaemonFileRepository $fileRepository): array
    {
        $updatedCount = 0;
        $failedCount = 0;
        $installedMods = $this->getInstalledModsMetadata();
        $registry = app(ProjectSourceRegistry::class);
        $type = static::detectProjectType($server);

        foreach ($installedMods as $installedMod) {
            try {
                if (!isset($installedMod['project_id'], $installedMod['project_slug'], $installedMod['project_title'], $installedMod['version_id'])) {
                    continue;
                }

                $sourceKey = ProjectSourceKey::tryFrom($installedMod['source'] ?? '') ?? ProjectSourceKey::Modrinth;
                $source = $registry->get($sourceKey);

                if (!$source || !$type) {
                    continue;
                }

                $versions = $source->getVersions($installedMod['project_id'], $server, $type);
                if (empty($versions) || !isset($versions[0]['id'], $versions[0]['version_number'], $versions[0]['files'])) {
                    continue;
                }

                $latestVersion = $versions[0];
                if ($installedMod['version_id'] === $latestVersion['id']) {
                    continue;
                }

                $primaryFile = $this->getPrimaryFile($latestVersion['files']);
                if (!$primaryFile) {
                    throw new Exception('No downloadable file found for bulk update');
                }

                $record = [
                    'project_id' => $installedMod['project_id'],
                    'slug' => $installedMod['project_slug'],
                    'title' => $installedMod['project_title'],
                    'author' => $installedMod['author'] ?? null,
                    'source' => $sourceKey->value,
                ];

                $this->performInstallOrUpdate($server, $fileRepository, $record, $latestVersion, $primaryFile, $installedMod);
                $updatedCount++;
            } catch (Exception $exception) {
                report($exception);
                $failedCount++;
            }
        }

        if ($updatedCount > 0 || $failedCount > 0) {
            $this->installedModsMetadata = null;
            $this->versionsCache = [];
        }

        return [
            'updated' => $updatedCount,
            'failed' => $failedCount,
        ];
    }

    /**
     * @param array<string, mixed> $record
     *
     * @throws Exception
     */
    private function getUninstallFilename(array $record): string
    {
        if (($record['untracked'] ?? false) === true) {
            return $this->validateFilename((string) ($record['title'] ?? ''));
        }

        if (empty($record['project_id'])) {
            throw new Exception('Missing project ID for uninstall');
        }

        $installedMod = $this->getInstalledMod($record['project_id'], $record['source'] ?? ProjectSourceKey::Modrinth->value);

        if (!$installedMod) {
            throw new Exception('Mod not found in metadata');
        }

        return $this->validateFilename($installedMod['filename']);
    }

    /**
     * @throws Exception
     */
    public function table(Table $table): Table
    {
        return $table
            ->records(function (?string $search, int $page) {
                /** @var Server $server */
                $server = Filament::getTenant();
                $type = static::detectProjectType($server);

                if ($this->activeTab === 'installed') {
                    $perPage = 20;
                    $installedMods = $this->getInstalledModsMetadata();
                    $unknownFiles = [];

                    /** @var DaemonFileRepository $fileRepository */
                    $fileRepository = app(DaemonFileRepository::class);

                    $metadataBefore = count($installedMods);

                    try {
                        $this->unknownFiles = MinecraftModrinth::scanAndImportMods($server, $fileRepository, $type);
                        $unknownFiles = $this->unknownFiles;

                        $this->installedModsMetadata = null;
                        $installedMods = $this->getInstalledModsMetadata();

                        $importedCount = max(0, count($installedMods) - $metadataBefore);
                        if ($importedCount > 0) {
                            Notification::make()
                                ->title(trans('pelican-minecraft-modrinth::strings.notifications.scan_success', ['count' => $importedCount]))
                                ->success()
                                ->send();
                        }
                    } catch (Exception $exception) {
                        report($exception);

                        Notification::make()
                            ->title(trans('pelican-minecraft-modrinth::strings.notifications.scan_failed'))
                            ->danger()
                            ->send();

                        $this->unknownFiles = [];
                    }

                    if ($search) {
                        $searchLower = strtolower($search);
                        $installedMods = array_values(array_filter($installedMods, function (array $mod) use ($searchLower) {
                            return str_contains(strtolower($mod['project_title']), $searchLower)
                                || str_contains(strtolower($mod['project_slug']), $searchLower);
                        }));

                        $unknownFiles = array_values(array_filter($unknownFiles, fn (string $filename) => str_contains(strtolower($filename), $searchLower)));
                    }

                    $projects = !empty($installedMods)
                        ? app(ProjectSourceRegistry::class)->hydrateInstalled($installedMods)
                        : [];

                    foreach ($unknownFiles as $filename) {
                        $projects[] = [
                            'project_id' => null,
                            'slug' => null,
                            'title' => $filename,
                            'description' => null,
                            'icon_url' => null,
                            'author' => null,
                            'downloads' => null,
                            'date_modified' => null,
                            'source' => null,
                            'untracked' => true,
                        ];
                    }

                    $totalCount = count($installedMods) + count($unknownFiles);
                    $offset = ($page - 1) * $perPage;
                    $pagedProjects = array_slice($projects, $offset, $perPage);

                    return new LengthAwarePaginator($pagedProjects, $totalCount, $perPage, $page);
                }

                $currentSource = $this->getCurrentSource();

                if (!$type || !$currentSource || !$currentSource->isConfigured() || !$currentSource->supportsSearch()) {
                    return new LengthAwarePaginator([], 0, 20, $page);
                }

                $response = $currentSource->search($server, $type, $page, $search);

                $hits = array_map(function (array $hit) use ($currentSource) {
                    $hit['source'] = $currentSource->getKey()->value;

                    return $hit;
                }, $response['hits']);

                return new LengthAwarePaginator($hits, $response['total_hits'], 20, $page);
            })
            ->paginated([20])
            ->emptyStateHeading(function () {
                $currentSource = $this->getCurrentSource();

                if ($this->activeTab !== 'installed' && $currentSource && !$currentSource->isConfigured()) {
                    return trans('pelican-minecraft-modrinth::strings.page.source_not_configured_heading');
                }

                return null;
            })
            ->emptyStateDescription(function () {
                $currentSource = $this->getCurrentSource();

                if ($this->activeTab !== 'installed' && $currentSource && !$currentSource->isConfigured()) {
                    return trans('pelican-minecraft-modrinth::strings.page.source_not_configured');
                }

                return null;
            })
            ->columns([
                ImageColumn::make('icon_url')
                    ->label(''),
                TextColumn::make('title')
                    ->searchable()
                    ->wrap()
                    ->lineClamp(1)
                    ->description(function (array $record): ?string {
                        if ($record['untracked'] ?? false) {
                            return trans('pelican-minecraft-modrinth::strings.badges.not_on_modrinth');
                        }

                        $description = $record['description'] ?? null;
                        if (!is_string($description)) {
                            return null;
                        }

                        return (strlen($description) > 120) ? substr($description, 0, 120).'...' : $description;
                    }),
                TextColumn::make('source')
                    ->label(trans('pelican-minecraft-modrinth::strings.table.columns.source'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => $this->getSourceLabel($state))
                    ->color(fn (?string $state) => match ($state) {
                        'modrinth' => 'success',
                        'curseforge' => 'warning',
                        'hangar' => 'info',
                        'github_releases' => 'gray',
                        default => 'gray',
                    })
                    ->visible(fn () => $this->activeTab === 'installed' && count($this->getAvailableSources()) > 1)
                    ->toggleable(),
                TextColumn::make('author')
                    ->url(fn (array $record, $state) => (($record['source'] ?? null) === ProjectSourceKey::Modrinth->value && $state) ? "https://modrinth.com/user/$state" : null, true)
                    ->toggleable(),
                TextColumn::make('downloads')
                    ->icon('tabler-download')
                    ->numeric()
                    ->toggleable(),
                TextColumn::make('date_modified')
                    ->icon('tabler-calendar')
                    ->formatStateUsing(fn ($state) => $state ? Carbon::parse($state, 'UTC')->diffForHumans() : '')
                    ->tooltip(fn ($state) => $state ? Carbon::parse($state, 'UTC')->timezone(user()->timezone ?? 'UTC')->format($table->getDefaultDateTimeDisplayFormat()) : '')
                    ->toggleable(),
            ])
            ->recordUrl(function (array $record) {
                if (!empty($record['unavailable']) || ($record['untracked'] ?? false)) {
                    return null;
                }

                return $this->getExternalProjectUrl($record);
            }, true)
            ->recordActions([
                Action::make('versions')
                    ->iconButton()
                    ->extraAttributes(['class' => 'mx-0.5'])
                    ->icon('tabler-list')
                    ->color('info')
                    ->tooltip(trans('pelican-minecraft-modrinth::strings.actions.versions'))
                    ->hidden(fn (array $record): bool => $record['untracked'] ?? false)
                    ->modalSubmitAction(false)
                    ->schema(function (array $record) {
                        $sourceKey = $record['source'] ?? ProjectSourceKey::Modrinth->value;
                        $versions = $this->getCachedVersions($record['project_id'], $sourceKey);

                        $installedMod = $this->getInstalledMod($record['project_id'], $sourceKey);
                        $installedVersionId = $installedMod['version_id'] ?? null;

                        $sections = [];
                        foreach ($versions as $versionIndex => $versionData) {
                            $primaryFile = $this->getPrimaryFile($versionData['files'] ?? []);

                            $sectionComponents = [
                                TextEntry::make('type_' . $versionIndex)
                                    ->label(trans('pelican-minecraft-modrinth::strings.version.type'))
                                    ->state($versionData['version_type'] ?? '')
                                    ->badge()
                                    ->color(match ($versionData['version_type'] ?? '') {
                                        'release' => 'success',
                                        'beta' => 'warning',
                                        'alpha' => 'danger',
                                        default => 'gray',
                                    }),
                                TextEntry::make('downloads_' . $versionIndex)
                                    ->label(trans('pelican-minecraft-modrinth::strings.version.downloads'))
                                    ->state($versionData['downloads'] ?? 0)
                                    ->icon('tabler-download')
                                    ->numeric(),
                                TextEntry::make('published_' . $versionIndex)
                                    ->label(trans('pelican-minecraft-modrinth::strings.version.published'))
                                    ->state(fn () => isset($versionData['date_published']) ? Carbon::parse($versionData['date_published'], 'UTC')->diffForHumans() : ''),
                            ];

                            if (!empty($versionData['changelog'])) {
                                $sectionComponents[] = TextEntry::make('changelog_' . $versionIndex)
                                    ->label(trans('pelican-minecraft-modrinth::strings.version.changelog'))
                                    ->state($versionData['changelog'])
                                    ->markdown();
                            }

                            if (($versionData['id'] ?? null) === $installedVersionId) {
                                $headerAction = Action::make('installed_' . $versionIndex)
                                    ->label(trans('pelican-minecraft-modrinth::strings.actions.installed'))
                                    ->icon('tabler-check')
                                    ->color('success')
                                    ->disabled();
                                $sectionIcon = 'tabler-check';
                                $sectionIconColor = 'success';
                            } else {
                                $headerAction = Action::make('install_version_' . $versionIndex)
                                    ->label(trans('pelican-minecraft-modrinth::strings.actions.install'))
                                    ->icon('tabler-download')
                                    ->action(function (DaemonFileRepository $fileRepository) use ($record, $versionData, $primaryFile, $sourceKey) {
                                        try {
                                            /** @var Server $server */
                                            $server = Filament::getTenant();

                                            if (!isset($versionData['id'], $versionData['version_number'], $versionData['files'])) {
                                                throw new Exception('Invalid version data structure');
                                            }

                                            if (!$primaryFile) {
                                                throw new Exception('No downloadable file found');
                                            }

                                            $installedMod = $this->getInstalledMod($record['project_id'], $sourceKey);

                                            $this->performInstallOrUpdate($server, $fileRepository, $record, $versionData, $primaryFile, $installedMod);

                                            $this->installedModsMetadata = null;
                                            $this->versionsCache = [];
                                            $this->js('$wire.$refresh()');

                                            Notification::make()
                                                ->title(trans('pelican-minecraft-modrinth::strings.notifications.install_success'))
                                                ->body(trans('pelican-minecraft-modrinth::strings.notifications.install_success_body', [
                                                    'name' => $record['title'],
                                                    'version' => $versionData['version_number'],
                                                ]))
                                                ->success()
                                                ->send();
                                        } catch (Exception $exception) {
                                            report($exception);

                                            $this->installedModsMetadata = null;
                                            $this->versionsCache = [];
                                            $this->js('$wire.$refresh()');

                                            Notification::make()
                                                ->title(trans('pelican-minecraft-modrinth::strings.notifications.install_failed'))
                                                ->body(trans('pelican-minecraft-modrinth::strings.notifications.install_failed_body'))
                                                ->danger()
                                                ->send();
                                        }
                                    });
                                $sectionIcon = null;
                                $sectionIconColor = null;
                            }

                            $section = Section::make($versionData['version_number'] ?? '')
                                ->headerActions([$headerAction])
                                ->schema($sectionComponents)
                                ->collapsible()
                                ->collapsed(!($versionData['featured'] ?? false));

                            if ($sectionIcon !== null) {
                                $section = $section->icon($sectionIcon)->iconColor($sectionIconColor);
                            }

                            $sections[] = $section;
                        }

                        return $sections;
                    }),
                Action::make('install_latest')
                    ->iconButton()
                    ->extraAttributes(['class' => 'mx-0.5'])
                    ->icon('tabler-download')
                    ->color('success')
                    ->tooltip(trans('pelican-minecraft-modrinth::strings.actions.install_latest'))
                    ->hidden(fn (array $record): bool => $record['untracked'] ?? false)
                    ->visible(function (array $record) {
                        if (empty($record['project_id'])) {
                            return false;
                        }

                        return is_null($this->getInstalledMod($record['project_id'], $record['source'] ?? ProjectSourceKey::Modrinth->value));
                    })
                    ->action(function (array $record, DaemonFileRepository $fileRepository) {
                        try {
                            /** @var Server $server */
                            $server = Filament::getTenant();
                            $type = static::detectProjectType($server);

                            $sourceKey = ProjectSourceKey::tryFrom($record['source'] ?? '') ?? ProjectSourceKey::Modrinth;
                            $source = app(ProjectSourceRegistry::class)->get($sourceKey);

                            if (!$source || !$type) {
                                throw new Exception('Source unavailable');
                            }

                            $versions = $source->getVersions($record['project_id'], $server, $type);

                            if (empty($versions)) {
                                throw new Exception('No compatible versions found');
                            }

                            $latestVersion = $versions[0];

                            if (!isset($latestVersion['id'], $latestVersion['version_number'], $latestVersion['files'])) {
                                throw new Exception('Invalid version data structure');
                            }

                            $primaryFile = $this->getPrimaryFile($latestVersion['files']);

                            if (!$primaryFile) {
                                throw new Exception('No downloadable file found');
                            }

                            $this->performInstallOrUpdate($server, $fileRepository, $record, $latestVersion, $primaryFile);

                            $this->installedModsMetadata = null;
                            $this->versionsCache = [];

                            Notification::make()
                                ->title(trans('pelican-minecraft-modrinth::strings.notifications.install_success'))
                                ->body(trans('pelican-minecraft-modrinth::strings.notifications.install_success_body', [
                                    'name' => $record['title'],
                                    'version' => $latestVersion['version_number'],
                                ]))
                                ->success()
                                ->send();
                        } catch (Exception $exception) {
                            report($exception);

                            $this->installedModsMetadata = null;
                            $this->versionsCache = [];

                            Notification::make()
                                ->title(trans('pelican-minecraft-modrinth::strings.notifications.install_failed'))
                                ->body(trans('pelican-minecraft-modrinth::strings.notifications.install_failed_body'))
                                ->danger()
                                ->send();
                        }
                    }),
                Action::make('update')
                    ->iconButton()
                    ->extraAttributes(['class' => 'mx-0.5'])
                    ->icon('tabler-refresh')
                    ->color('warning')
                    ->tooltip(trans('pelican-minecraft-modrinth::strings.actions.update'))
                    ->hidden(fn (array $record): bool => $record['untracked'] ?? false)
                    ->visible(function (array $record) {
                        if (empty($record['project_id'])) {
                            return false;
                        }

                        $sourceKey = $record['source'] ?? ProjectSourceKey::Modrinth->value;
                        $installedMod = $this->getInstalledMod($record['project_id'], $sourceKey);

                        if (is_null($installedMod)) {
                            return false;
                        }

                        $versions = $this->getCachedVersions($record['project_id'], $sourceKey);

                        if (empty($versions)) {
                            return false;
                        }

                        return $installedMod['version_id'] !== $versions[0]['id'];
                    })
                    ->requiresConfirmation()
                    ->modalHeading(trans('pelican-minecraft-modrinth::strings.modals.update_heading'))
                    ->modalDescription(function (array $record) {
                        $sourceKey = $record['source'] ?? ProjectSourceKey::Modrinth->value;
                        $installedMod = $this->getInstalledMod($record['project_id'], $sourceKey);
                        $versions = $this->getCachedVersions($record['project_id'], $sourceKey);

                        return trans('pelican-minecraft-modrinth::strings.modals.update_description', [
                            'old_version' => $installedMod['version_number'] ?? 'unknown',
                            'new_version' => $versions[0]['version_number'] ?? 'unknown',
                        ]);
                    })
                    ->action(function (array $record, DaemonFileRepository $fileRepository) {
                        try {
                            /** @var Server $server */
                            $server = Filament::getTenant();
                            $type = static::detectProjectType($server);

                            $sourceKey = ProjectSourceKey::tryFrom($record['source'] ?? '') ?? ProjectSourceKey::Modrinth;
                            $installedMod = $this->getInstalledMod($record['project_id'], $sourceKey->value);

                            if (!$installedMod) {
                                throw new Exception('Mod not found in metadata');
                            }

                            $source = app(ProjectSourceRegistry::class)->get($sourceKey);

                            if (!$source || !$type) {
                                throw new Exception('Source unavailable');
                            }

                            $versions = $source->getVersions($record['project_id'], $server, $type);

                            if (empty($versions)) {
                                throw new Exception('No compatible versions found');
                            }

                            $latestVersion = $versions[0];

                            if (!isset($latestVersion['id'], $latestVersion['version_number'], $latestVersion['files'])) {
                                throw new Exception('Invalid version data structure');
                            }

                            $primaryFile = $this->getPrimaryFile($latestVersion['files']);

                            if (!$primaryFile) {
                                throw new Exception('No downloadable file found');
                            }

                            $this->performInstallOrUpdate($server, $fileRepository, $record, $latestVersion, $primaryFile, $installedMod);

                            $this->installedModsMetadata = null;
                            $this->versionsCache = [];

                            Notification::make()
                                ->title(trans('pelican-minecraft-modrinth::strings.notifications.update_success'))
                                ->body(trans('pelican-minecraft-modrinth::strings.notifications.update_success_body', [
                                    'version' => $latestVersion['version_number'],
                                ]))
                                ->success()
                                ->send();
                        } catch (Exception $exception) {
                            report($exception);

                            $this->installedModsMetadata = null;
                            $this->versionsCache = [];

                            Notification::make()
                                ->title(trans('pelican-minecraft-modrinth::strings.notifications.update_failed'))
                                ->body(trans('pelican-minecraft-modrinth::strings.notifications.update_failed_body'))
                                ->danger()
                                ->send();
                        }
                    }),
                Action::make('installed')
                    ->iconButton()
                    ->extraAttributes(['class' => 'mx-0.5'])
                    ->icon('tabler-check')
                    ->color('success')
                    ->tooltip(trans('pelican-minecraft-modrinth::strings.actions.installed'))
                    ->disabled()
                    ->hidden(fn (array $record): bool => $record['untracked'] ?? false)
                    ->visible(function (array $record) {
                        if (empty($record['project_id'])) {
                            return false;
                        }

                        $sourceKey = $record['source'] ?? ProjectSourceKey::Modrinth->value;
                        $installedMod = $this->getInstalledMod($record['project_id'], $sourceKey);

                        if (is_null($installedMod)) {
                            return false;
                        }

                        $versions = $this->getCachedVersions($record['project_id'], $sourceKey);

                        if (empty($versions)) {
                            return true;
                        }

                        return $installedMod['version_id'] === $versions[0]['id'];
                    }),
                Action::make('uninstall')
                    ->iconButton()
                    ->extraAttributes(['class' => 'mx-0.5'])
                    ->icon('tabler-trash')
                    ->color('danger')
                    ->tooltip(trans('pelican-minecraft-modrinth::strings.actions.uninstall'))
                    ->visible(function (array $record) {
                        if (($record['untracked'] ?? false) === true) {
                            return true;
                        }

                        if (empty($record['project_id'])) {
                            return false;
                        }

                        return !is_null($this->getInstalledMod($record['project_id'], $record['source'] ?? ProjectSourceKey::Modrinth->value));
                    })
                    ->requiresConfirmation()
                    ->modalHeading(fn (array $record) => trans('pelican-minecraft-modrinth::strings.modals.uninstall_heading'))
                    ->modalDescription(fn (array $record) => trans('pelican-minecraft-modrinth::strings.modals.uninstall_description', ['name' => $record['title']]))
                    ->action(function (array $record, DaemonFileRepository $fileRepository) {
                        try {
                            /** @var Server $server */
                            $server = Filament::getTenant();

                            $safeFilename = $this->getUninstallFilename($record);

                            $type = static::detectProjectType($server);
                            if (!$type) {
                                throw new Exception('Server does not support Modrinth mods or plugins');
                            }

                            $folder = MinecraftModrinth::getProjectFolder($server, $fileRepository, $type);

                            Http::daemon($server->node)
                                ->post("/api/servers/{$server->uuid}/files/delete", [
                                    'root' => '/',
                                    'files' => [$folder.'/'.$safeFilename],
                                ])
                                ->throw();

                            Cache::forget(MinecraftModrinth::getHashScanCacheKey($server, $type));
                            $this->unknownFiles = array_values(
                                array_filter($this->unknownFiles, fn (string $filename) => strtolower($filename) !== strtolower($safeFilename))
                            );

                            $sourceKey = ProjectSourceKey::tryFrom($record['source'] ?? '') ?? ProjectSourceKey::Modrinth;

                            $metadataRemoved = true;
                            if (!empty($record['project_id'])) {
                                $metadataRemoved = MinecraftModrinth::removeModMetadata($server, $fileRepository, $record['project_id'], $type, $sourceKey);
                            }

                            if (!$metadataRemoved) {
                                Log::warning('Failed to remove mod metadata after successful file deletion', [
                                    'project_id' => $record['project_id'],
                                    'source' => $sourceKey->value,
                                    'server_id' => $server->id,
                                ]);

                                if (is_array($this->installedModsMetadata)) {
                                    $this->installedModsMetadata = array_values(
                                        array_filter(
                                            $this->installedModsMetadata,
                                            fn ($mod) => !($mod['project_id'] === $record['project_id'] && ($mod['source'] ?? ProjectSourceKey::Modrinth->value) === $sourceKey->value)
                                        )
                                    );
                                }

                                unset($this->versionsCache["{$sourceKey->value}:{$record['project_id']}"]);
                            } else {
                                $this->installedModsMetadata = null;
                                $this->versionsCache = [];
                            }

                            if ($this->activeTab === 'installed') {
                                $this->js('$wire.$refresh()');
                            }

                            Notification::make()
                                ->title(trans('pelican-minecraft-modrinth::strings.notifications.uninstall_success'))
                                ->body(trans('pelican-minecraft-modrinth::strings.notifications.uninstall_success_body', [
                                    'name' => $record['title'],
                                ]))
                                ->success()
                                ->send();
                        } catch (Exception $exception) {
                            report($exception);

                            $this->installedModsMetadata = null;
                            $this->versionsCache = [];

                            if ($this->activeTab === 'installed') {
                                $this->js('$wire.$refresh()');
                            }

                            Notification::make()
                                ->title(trans('pelican-minecraft-modrinth::strings.notifications.uninstall_failed'))
                                ->body(trans('pelican-minecraft-modrinth::strings.notifications.uninstall_failed_body'))
                                ->danger()
                                ->send();
                        }
                    }),
            ]);
    }

    protected function getHeaderActions(): array
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        $type = static::detectProjectType($server);
        if (!$type) {
            return [];
        }

        /** @var DaemonFileRepository $fileRepository */
        $fileRepository = app(DaemonFileRepository::class);
        $folder = MinecraftModrinth::getProjectFolder($server, $fileRepository, $type);

        $githubSource = app(ProjectSourceRegistry::class)->get(ProjectSourceKey::GitHubReleases);
        $availableSourceKeys = array_map(fn (ProjectSourceInterface $source) => $source->getKey()->value, $this->getAvailableSources());
        $githubAvailable = $githubSource
            && $githubSource->supportsProjectType($type)
            && in_array(ProjectSourceKey::GitHubReleases->value, $availableSourceKeys, true);

        return [
            Action::make('open_folder')
                ->tooltip(fn () => trans('pelican-minecraft-modrinth::strings.page.open_folder', ['folder' => $folder]))
                ->icon('tabler-folder-open')
                ->url(fn () => ListFiles::getUrl(['path' => $folder]), true),
            Action::make('track_github_repo')
                ->label(trans('pelican-minecraft-modrinth::strings.actions.track_github_repo'))
                ->icon('tabler-brand-github')
                ->disabled(fn () => !$githubSource?->isConfigured())
                ->tooltip(fn () => $githubSource?->isConfigured() ? null : trans('pelican-minecraft-modrinth::strings.page.source_not_configured'))
                ->schema([
                    TextInput::make('repository')
                        ->label(trans('pelican-minecraft-modrinth::strings.page.github_repo_label'))
                        ->placeholder('owner/repo')
                        ->helperText(trans('pelican-minecraft-modrinth::strings.page.github_repo_helper'))
                        ->required(),
                ])
                ->action(function (array $data, DaemonFileRepository $fileRepository) use ($server, $type, $githubSource) {
                    try {
                        if (!$githubSource) {
                            throw new Exception('GitHub Releases source not available');
                        }

                        $project = $githubSource->resolveProjectByIdentifier(trim($data['repository']));

                        if (!$project) {
                            throw new Exception('Repository not found');
                        }

                        $versions = $githubSource->getVersions($project['project_id'], $server, $type);

                        if (empty($versions) || !isset($versions[0]['id'], $versions[0]['version_number'], $versions[0]['files'])) {
                            throw new Exception('No installable release found for this repository');
                        }

                        $latestVersion = $versions[0];
                        $primaryFile = $this->getPrimaryFile($latestVersion['files']);

                        if (!$primaryFile) {
                            throw new Exception('No downloadable file found');
                        }

                        $record = [
                            'project_id' => $project['project_id'],
                            'slug' => $project['slug'],
                            'title' => $project['title'],
                            'author' => $project['author'] ?? null,
                            'source' => ProjectSourceKey::GitHubReleases->value,
                        ];

                        $this->performInstallOrUpdate($server, $fileRepository, $record, $latestVersion, $primaryFile);

                        $this->installedModsMetadata = null;
                        $this->versionsCache = [];
                        $this->js('$wire.$refresh()');

                        Notification::make()
                            ->title(trans('pelican-minecraft-modrinth::strings.notifications.install_success'))
                            ->body(trans('pelican-minecraft-modrinth::strings.notifications.install_success_body', [
                                'name' => $project['title'],
                                'version' => $latestVersion['version_number'],
                            ]))
                            ->success()
                            ->send();
                    } catch (Exception $exception) {
                        report($exception);

                        Notification::make()
                            ->title(trans('pelican-minecraft-modrinth::strings.notifications.install_failed'))
                            ->body(trans('pelican-minecraft-modrinth::strings.notifications.install_failed_body'))
                            ->danger()
                            ->send();
                    }
                })
                ->visible(fn () => $githubAvailable),
            Action::make('update_all')
                ->label(fn () => trans(match ($type) {
                    ModrinthProjectType::Plugin => 'pelican-minecraft-modrinth::strings.actions.update_all_plugins',
                    ModrinthProjectType::Datapack => 'pelican-minecraft-modrinth::strings.actions.update_all_datapacks',
                    default => 'pelican-minecraft-modrinth::strings.actions.update_all_mods',
                }))
                ->icon('tabler-download')
                ->color('warning')
                ->requiresConfirmation()
                ->action(function (DaemonFileRepository $fileRepository) use ($server) {
                    $result = $this->performBulkUpdate($server, $fileRepository);

                    if ($result['updated'] === 0 && $result['failed'] === 0) {
                        Notification::make()
                            ->title(trans('pelican-minecraft-modrinth::strings.notifications.bulk_update_none'))
                            ->info()
                            ->send();

                        return;
                    }

                    if ($result['failed'] > 0) {
                        Notification::make()
                            ->title(trans('pelican-minecraft-modrinth::strings.notifications.bulk_update_partial', [
                                'updated' => $result['updated'],
                                'failed' => $result['failed'],
                            ]))
                            ->warning()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title(trans('pelican-minecraft-modrinth::strings.notifications.bulk_update_success', [
                            'count' => $result['updated'],
                        ]))
                        ->success()
                        ->send();
                })
                ->visible(fn () => static::detectProjectType($server) !== null && $this->activeTab === 'installed'),
            Action::make('scan_mods')
                ->label(trans('pelican-minecraft-modrinth::strings.actions.scan'))
                ->tooltip(fn () => trans(match ($type) {
                    ModrinthProjectType::Plugin => 'pelican-minecraft-modrinth::strings.actions.rescan_plugins_for_updates',
                    ModrinthProjectType::Datapack => 'pelican-minecraft-modrinth::strings.actions.rescan_datapacks_for_updates',
                    default => 'pelican-minecraft-modrinth::strings.actions.rescan_mods_for_updates',
                }))
                ->icon('tabler-search')
                ->action(function () use ($server, $type) {
                    Cache::forget(MinecraftModrinth::getHashScanCacheKey($server, $type));
                    $this->redirect(static::getUrl());
                })
                ->visible(fn () => static::detectProjectType($server) !== null),
        ];
    }

    public function content(Schema $schema): Schema
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        $type = static::detectProjectType($server);

        return $schema
            ->components([
                Grid::make($type === ModrinthProjectType::Datapack ? 4 : 3)
                    ->extraAttributes(['class' => 'mmr-page-header'])
                    ->schema([
                        TextEntry::make('Minecraft Version')
                            ->state(fn () => MinecraftModrinth::getMinecraftVersion($server) ?? trans('pelican-minecraft-modrinth::strings.page.unknown'))
                            ->badge()
                            ->size(TextSize::Large),
                        TextEntry::make('World')
                            ->state(fn (DaemonFileRepository $fileRepository) => $this->getCachedDatapackWorldName($server, $fileRepository))
                            ->badge()
                            ->size(TextSize::Large)
                            ->visible(fn () => $type === ModrinthProjectType::Datapack),
                        TextEntry::make('Loader')
                            ->state(fn () => MinecraftLoader::fromServer($server)?->getLabel() ?? trans('pelican-minecraft-modrinth::strings.page.unknown'))
                            ->icon(function () use ($server) {
                                $loader = MinecraftLoader::fromServer($server);
                                if (!$loader) {
                                    return null;
                                }
                                $name = strtolower($loader->name);
                                $path = plugin_path('pelican-minecraft-modrinth', 'resources/icons/loaders/' . $name . '.svg');

                                return file_exists($path) ? 'mcloader-' . $name : null;
                            })
                            ->badge()
                            ->size(TextSize::Large)
                            ->extraAttributes(['class' => 'mcloader-badge']),
                        TextEntry::make('installed')
                            ->label(fn () => trans('pelican-minecraft-modrinth::strings.page.installed', ['type' => $type?->getLabel() ?? 'Modrinth']))
                            ->state(function (DaemonFileRepository $fileRepository) use ($server, $type) {
                                try {
                                    if (!$type) {
                                        return trans('pelican-minecraft-modrinth::strings.page.unknown');
                                    }

                                    $files = $fileRepository->setServer($server)->getDirectory(MinecraftModrinth::getProjectFolder($server, $fileRepository, $type));

                                    if (isset($files['error'])) {
                                        throw new Exception($files['error']);
                                    }

                                    $extension = $type->getFileExtension();

                                    return collect($files)
                                        ->filter(fn ($file) => str($file['name'])->lower()->endsWith($extension))
                                        ->count();
                                } catch (Exception $exception) {
                                    report($exception);

                                    return trans('pelican-minecraft-modrinth::strings.page.unknown');
                                }
                            })
                            ->badge()
                            ->size(TextSize::Large),
                    ]),
                $this->getTabsContentComponent(),
                Group::make([
                    EmbeddedTable::make(),
                ])->extraAttributes([
                    'class' => 'mmr-table-scroll-ctn',
                    // A CSS calc() estimate of "space above the table" is inherently
                    // fragile (topbar/sidebar height, and this page's own header
                    // wrapping, all vary), and getting it wrong causes the page
                    // itself to scroll in addition to the table - measure the
                    // actual remaining viewport space instead, so the table always
                    // fits exactly regardless of layout specifics. The Livewire
                    // component queues the calculation after the initial render
                    // and each active-tab update; Alpine x-init would only run on
                    // first mount because Livewire retains this wrapper while
                    // morphing the table contents beneath it.
                ]),
            ]);
    }
}
