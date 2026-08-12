<?php

namespace Kazaminosuke\ModManager\Filament\Server\Pages;

use App\Enums\SubuserPermission;
use App\Filament\Admin\Resources\Plugins\PluginResource;
use App\Filament\Server\Resources\Files\Pages\ListFiles;
use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use App\Traits\Filament\BlockAccessInConflict;
use Exception;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Resources\Concerns\HasTabs;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Enums\TextSize;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Kazaminosuke\ModManager\Contracts\ProjectSourceInterface;
use Kazaminosuke\ModManager\Enums\MinecraftLoader;
use Kazaminosuke\ModManager\Enums\ProjectOperation;
use Kazaminosuke\ModManager\Enums\ProjectSourceKey;
use Kazaminosuke\ModManager\Enums\ProjectType;
use Kazaminosuke\ModManager\Facades\ModManager;
use Kazaminosuke\ModManager\Jobs\WarmCatalogSearch;
use Kazaminosuke\ModManager\ModManagerPlugin;
use Kazaminosuke\ModManager\Services\InstalledOperationManager;
use Kazaminosuke\ModManager\Services\VersionLookupCoordinator;
use Kazaminosuke\ModManager\Support\CacheVersion;
use Kazaminosuke\ModManager\Support\EggProfileResolver;
use Kazaminosuke\ModManager\Support\InstalledOperationState;
use Kazaminosuke\ModManager\Support\InstalledScanResult;
use Kazaminosuke\ModManager\Support\ProjectIconUrl;
use Kazaminosuke\ModManager\Support\ProjectOperationAuthorizer;
use Kazaminosuke\ModManager\Support\ProjectSourceRegistry;
use Livewire\Attributes\Locked;

class ModManagerPage extends Page implements HasTable
{
    use BlockAccessInConflict;
    use HasTabs {
        HasTabs::updatedActiveTab as protected baseUpdatedActiveTab;
    }
    use InteractsWithTable {
        InteractsWithTable::applyTableColumnManager as protected baseApplyTableColumnManager;
        InteractsWithTable::loadTable as protected baseLoadTable;
        InteractsWithTable::resetTableColumnManager as protected baseResetTableColumnManager;
        InteractsWithTable::updatedTableFilters as protected baseUpdatedTableFilters;
        InteractsWithTable::updatedTableSearch as protected baseUpdatedTableSearch;
    }

    /** Keep every catalog source and the table paginator on the same page size. */
    private const TABLE_PAGE_SIZE = 20;

    /** A success outcome remains visible long enough to be read, but never persists. */
    private const INSTALLED_SCAN_COMPLETION_VISIBLE_SECONDS = 5;

    /** @var array<int, array{source: string, project_id: string, project_slug: string, project_title: string, version_id: string, version_number: string, filename: string, installed_at: string, author?: string}>|null */
    protected ?array $installedModsMetadata = null;

    /** @var array<string, array<string, mixed>>|null */
    protected ?array $installedModsIndex = null;

    /** @var array<string, array<int, mixed>> Cache for version data by "source:project_id" */
    protected array $versionsCache = [];

    /** @var array<string, array<string, mixed>|null> Latest compatible version by "source:project_id" */
    protected array $latestVersionsCache = [];

    /**
     * Keys ("source:project_id") whose latest-version lookup was a cold
     * cache miss on the Installed tab's non-blocking peek path - a
     * background revalidation was queued rather than fetched inline. Set
     * only by peekVisibleLatestVersions(); the catalog tab's blocking
     * warmVisibleLatestVersions() never leaves an entry pending.
     *
     * @var array<string, true>
     */
    protected array $pendingLatestVersionKeys = [];

    /** @var array<int, ProjectSourceInterface>|null */
    protected ?array $availableSources = null;

    /** @var array<string> */
    protected array $unknownFiles = [];

    /**
     * The catalog sort is deliberately separate from Filament's table filters:
     * it changes result ordering but never narrows the result set.
     */
    public string $catalogSort = 'downloads';

    /** Null until a successful installed-scan cache provides the Wings file count. */
    public ?int $installedFilesCount = null;

    /** @var array<string, mixed>|null Browser-safe status payload for the active background operation. */
    public ?array $installedOperation = null;

    /** Prevent a completed operation from refreshing the deferred table more than once. */
    public ?string $handledInstalledOperation = null;

    /**
     * The scan operation observed while this component was on the Installed
     * tab. Its queued-at timestamp is immutable across the scan lifecycle,
     * so it identifies one scan without exposing daemon details.
     */
    public ?string $observedInstalledScan = null;

    /**
     * @var array<string, mixed>|null A short-lived successful scan outcome
     *                                for the Installed tab only. It is component-local so an old
     *                                completion message never returns after a browser reload.
     */
    public ?array $installedScanCompletion = null;

    /** Avoid repeating the operator-facing queue configuration warning in one component session. */
    public bool $operationQueueWarningShown = false;

    /** Enable polling only while an Installed operation needs observation. */
    public bool $pollInstalledOperations = false;

    /**
     * Enable a coarser, independent poll only while the Installed tab has
     * rows still waiting on a background enrichment fetch (icon/downloads/
     * date_modified or the latest-version/update badge). Deliberately kept
     * separate from pollInstalledOperations, which tracks scan/bulk-update
     * job state rather than passive cache-fill progress.
     */
    public bool $pollEnrichment = false;

    /** Whether a still-valid scan result already exists, independent of background-operation state. */
    public bool $installedScanDataReady = false;

    /** Per-request timing state used only by temporary initial-load diagnostics. */
    protected bool $modManagerTimingEnabled = false;

    protected float $modManagerTimingStartedAt = 0.0;

    protected string $modManagerTimingRequestId = '';

    protected int $modManagerTimingVersionLookups = 0;

    protected int $modManagerTimingVersionLookupDurationMs = 0;

    /**
     * Display-only component state. Keeping this in the Livewire snapshot
     * avoids another server.properties read for every poll request; a page
     * reload still resolves the current value from Wings.
     */
    #[Locked]
    public ?string $datapackWorldName = null;

    protected static string|\BackedEnum|null $navigationIcon = 'tabler-packages';

    protected static ?string $slug = 'mod-manager';

    public static function getNavigationSort(): ?int
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        return static::navigationSortFor(static::detectProjectType($server) ?? ProjectType::Mod);
    }

    protected static function navigationSortFor(ProjectType $type): int
    {
        return (int) config("pelican-minecraft-modrinth.navigation_sort.{$type->value}", 11);
    }

    protected static function detectProjectType(Server $server): ?ProjectType
    {
        return ProjectType::fromServer($server);
    }

    /**
     * Stage 8: an egg profile the auto-detection cascade recognizes as
     * plausibly Minecraft-related, but can't place automatically (a
     * modpack egg, or one this plugin has simply never seen before), still
     * makes this page accessible - content() then renders the manual-setup
     * notice/form instead of the normal catalog, rather than hiding the
     * page entirely the way an egg with nothing at all to do with
     * Minecraft correctly still does.
     *
     * MinecraftDatapackPage overrides needsManualEggSetup() back to always
     * false: the manual-setup prompt only ever appears once, on this page,
     * not duplicated on the datapack page too - see that class.
     */
    public static function canAccess(): bool
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        return parent::canAccess() && (static::detectProjectType($server) !== null || static::needsManualEggSetup($server));
    }

    protected static function needsManualEggSetup(Server $server): bool
    {
        if (!(bool) config('pelican-minecraft-modrinth.egg_autodetect_enabled', true)) {
            return false;
        }

        return EggProfileResolver::resolve($server)->needsManualSetup();
    }

    public static function getNavigationLabel(): string
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        $type = static::detectProjectType($server);

        return $type?->getLabel() ?? 'Managed';
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

    public function boot(): void
    {
        $this->modManagerTimingEnabled = (bool) config('pelican-minecraft-modrinth.debug_timing', false);

        if (!$this->modManagerTimingEnabled) {
            return;
        }

        $this->modManagerTimingStartedAt = microtime(true);
        $this->modManagerTimingRequestId = bin2hex(random_bytes(6));
        $this->modManagerTimingVersionLookups = 0;
        $this->modManagerTimingVersionLookupDurationMs = 0;

        request()->attributes->set('mmr_timing_request_id', $this->modManagerTimingRequestId);
        request()->attributes->set('mmr_timing_started_at', $this->modManagerTimingStartedAt);
    }

    public function dehydrate(): void
    {
        if (!$this->isModManagerTimingEnabled()) {
            return;
        }

        Log::info('Mod manager timing', [
            'stage' => 'total_component_request',
            'request_id' => $this->modManagerTimingRequestId,
            'duration_ms' => $this->getModManagerTimingElapsedMs(),
            'request_path' => request()->path(),
            'table_loaded' => $this->isTableLoaded,
            'version_lookup_count' => $this->modManagerTimingVersionLookups,
            'version_lookup_duration_ms' => $this->modManagerTimingVersionLookupDurationMs,
        ]);
    }

    protected function getModManagerTimingElapsedMs(?float $timestamp = null): int
    {
        if (!$this->isModManagerTimingEnabled()) {
            return 0;
        }

        return (int) round((($timestamp ?? microtime(true)) - $this->modManagerTimingStartedAt) * 1000);
    }

    protected function isModManagerTimingEnabled(): bool
    {
        return $this->modManagerTimingEnabled;
    }

    public function mount(): void
    {
        $this->catalogSort = $this->normalizeCatalogSort(
            session()->get($this->getCatalogSortSessionKey(), 'downloads'),
        );

        $this->refreshInstalledScanDataReady();
        $this->dispatchInstalledScanIfMissing();
        // HasTabs resolves and then caches getTabs() while choosing the default
        // tab. Read the persisted scan result first, otherwise that first
        // cached definition permanently misses the Installed count badge for
        // the whole component request (including after a browser reload).
        $this->loadDefaultActiveTab();
        $this->refreshInstalledOperationState();

        $this->dispatchCatalogWarm();
    }

    /**
     * Warm this visit's catalog page 1 (every available source) and the
     * active source's page 2, so a later visitor sharing the same (source,
     * project type, loader, Minecraft version, sort) combination gets a
     * fresh-cache hit instead of a cold miss. Too late to help this
     * request - records() has already run its own inline SourceCache
     * fetch by the time this warm job's result would land - see
     * WarmCatalogCacheCommand for what actually prevents a cold first
     * visit.
     */
    protected function dispatchCatalogWarm(): void
    {
        if (!(bool) config('pelican-minecraft-modrinth.warm_catalog_enabled', true)) {
            return;
        }

        // A sync/null queue driver would run this inline, during mount(),
        // defeating the entire point (and potentially blocking this
        // request on a throttled or slow upstream call).
        if (!app(InstalledOperationManager::class)->supportsAsyncDispatch()) {
            return;
        }

        /** @var Server $server */
        $server = Filament::getTenant();
        $type = static::detectProjectType($server);

        if (!$type) {
            return;
        }

        $loader = $type->getLoaderSlug($server);
        $mcVersion = ModManager::getMinecraftVersion($server);

        if (!$loader || !$mcVersion) {
            return;
        }

        $activeSourceKey = $this->getCurrentSource()?->getKey()->value;

        foreach ($this->getCatalogSources() as $source) {
            if (!$source->isConfigured()) {
                continue;
            }

            WarmCatalogSearch::dispatch(
                $server->id,
                $source->getKey()->value,
                $type->value,
                1,
                $loader,
                $mcVersion,
                $this->catalogSort,
            );

            if ($source->getKey()->value === $activeSourceKey) {
                WarmCatalogSearch::dispatch(
                    $server->id,
                    $source->getKey()->value,
                    $type->value,
                    2,
                    $loader,
                    $mcVersion,
                    $this->catalogSort,
                );
            }
        }
    }

    /**
     * A cheap cache-only check (no Wings API call, unlike the deferred
     * table's own scan) so the very first render already knows whether a
     * valid scan result exists - without this, installedScanDataReady stays
     * at its default false until the deferred table's records() closure
     * runs, so the status badge visibly flashes its "checking" state before
     * disappearing a moment later even when the data was ready all along.
     */
    protected function refreshInstalledScanDataReady(): void
    {
        /** @var Server $server */
        $server = Filament::getTenant();
        $type = static::detectProjectType($server);

        if (!$type) {
            return;
        }

        $scanCacheKey = ModManager::getHashScanCacheKey($server, $type);
        $this->setInstalledScanResult(InstalledScanResult::fromCache(Cache::get($scanCacheKey)));
    }

    /**
     * Start the first Installed scan from any mod-manager page, rather than
     * making the visitor discover the Installed tab just to populate its
     * count.  This is intentionally separate from catalog warming: it is a
     * per-server Wings request, not speculative traffic to a shared upstream
     * catalog API.
     *
     * The durable scan cache is the normal ten-minute cooldown. On a cache
     * miss, InstalledOperationManager's per-server/type active state prevents
     * repeat dispatches during a running scan; ScanInstalledProjects is also a
     * unique queued job, which closes the simultaneous-page-load race before
     * it can result in duplicate Wings scans.
     */
    protected function dispatchInstalledScanIfMissing(): void
    {
        if ($this->installedScanDataReady) {
            return;
        }

        /** @var Server $server */
        $server = Filament::getTenant();
        $type = static::detectProjectType($server);

        if (!$type) {
            return;
        }

        $dispatch = app(InstalledOperationManager::class)->dispatchScan($server, $type);
        $state = $dispatch['state'];

        if ($state !== null) {
            $this->setInstalledOperationState($state);
        }

        if ($dispatch['reason'] === 'sync_queue') {
            $this->operationQueueWarningShown = true;
            $this->pollInstalledOperations = false;

            Notification::make()
                ->title(trans('pelican-minecraft-modrinth::strings.operations.queue_required'))
                ->danger()
                ->send();
        }
    }

    /**
     * Keep the tab definition coherent with the durable scan cache. Filament's
     * HasTabs trait memoizes getTabs() in $cachedTabs, so merely changing the
     * public count property cannot update an already-rendered Installed badge.
     */
    protected function setInstalledScanResult(?InstalledScanResult $scanResult): void
    {
        $installedFilesCount = $scanResult?->diskFileCount;
        $changed = $this->installedFilesCount !== $installedFilesCount
            || $this->installedScanDataReady !== ($scanResult !== null);

        $this->installedScanDataReady = $scanResult !== null;
        $this->installedFilesCount = $installedFilesCount;

        if ($changed) {
            unset($this->cachedTabs);
        }
    }

    /** @return array<string, string> */
    protected function getCatalogSortOptions(): array
    {
        return [
            'downloads' => trans('pelican-minecraft-modrinth::strings.table.sort.downloads'),
            'updated' => trans('pelican-minecraft-modrinth::strings.table.sort.updated'),
            'popularity' => trans('pelican-minecraft-modrinth::strings.table.sort.popularity'),
        ];
    }

    protected function normalizeCatalogSort(mixed $sort): string
    {
        return is_string($sort) && array_key_exists($sort, $this->getCatalogSortOptions())
            ? $sort
            : 'downloads';
    }

    protected function getCatalogSortSessionKey(): string
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        return 'pelican-minecraft-modrinth.catalog-sort.'.$server->getKey();
    }

    public function updatedCatalogSort(mixed $sort): void
    {
        $this->isTableLoaded = false;
        $this->catalogSort = $this->normalizeCatalogSort($sort);
        session()->put($this->getCatalogSortSessionKey(), $this->catalogSort);

        $this->resetPage($this->getTablePaginationPageName());
        $this->resetTable();
    }

    public function updatedActiveTab(?string $activeTab): void
    {
        // Scan progress and its brief success outcome belong exclusively to
        // the Installed tab. Do not let a tab switch make a catalog visitor
        // see an operation that finished while they were browsing sources.
        if ($activeTab !== 'installed') {
            $this->observedInstalledScan = null;
            $this->installedScanCompletion = null;
        }

        // A loaded table normally evaluates its records during this same
        // Livewire update. Reset it to Filament's deferred state first, so an
        // Installed-tab hydration runs in the follow-up loadTable request
        // while the tab itself has already switched and shows its spinner.
        $this->isTableLoaded = false;

        // HasTabs::updatedActiveTab() (aliased above) already resets the table's
        // page - each tab (source or "installed") paginates its own independent
        // result set, so a page number from the previous tab has no meaning here
        // (e.g. leaving Modrinth on page 909 and switching to a CurseForge tab
        // with far fewer results) - plus resets the column manager state. It was
        // being silently dropped by this method overriding it without calling it.
        $this->baseUpdatedActiveTab();
        $this->refreshInstalledScanDataReady();
        // A long-lived component can outlive the ten-minute scan cache. A
        // catalog-tab switch is still a manager-page visit, so restore the
        // same cache-miss behavior as mount() instead of leaving the badge at
        // an ellipsis until the user opens Installed manually.
        $this->dispatchInstalledScanIfMissing();
        $this->refreshInstalledOperationState();

        // Category IDs and the Modrinth-only environment filter are scoped to
        // a source tab, so discard them before Filament rebuilds the form.
        // Catalog sorting is an independent Livewire property and stays intact.
        $this->tableFilters = [];
        $this->resetTable();
        $this->queueHeaderScroll();

    }

    public function updatedPaginators($page, $pageName): void
    {
        if ($pageName !== $this->getTablePaginationPageName()) {
            return;
        }

        $this->isTableLoaded = false;
        $this->queueHeaderScroll();
    }

    /**
     * Complete Filament's deferred initial table load, then resize the
     * newly-morphed table content just as we do after other table updates.
     */
    public function loadTable(): void
    {
        $startedAt = $this->isModManagerTimingEnabled() ? microtime(true) : 0.0;

        /** @var Server $server */
        $server = Filament::getTenant();
        $type = static::detectProjectType($server);
        $scanResult = $type === null
            ? null
            : InstalledScanResult::fromCache(
                Cache::get(ModManager::getHashScanCacheKey($server, $type)),
            );
        $this->setInstalledScanResult($scanResult);

        if ($this->isModManagerTimingEnabled()) {
            Log::info('Mod manager timing', [
                'stage' => 'load_table_prepare',
                'request_id' => $this->modManagerTimingRequestId,
                'active_tab' => $this->activeTab,
                'started_after_ms' => $this->getModManagerTimingElapsedMs($startedAt),
                'finished_after_ms' => $this->getModManagerTimingElapsedMs(),
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ]);
        }

        $this->baseLoadTable();
    }

    /**
     * Filament's deferred column manager does not update a
     * `tableColumnManager*` property. Its Alpine component directly invokes
     * this method via `$wire.call()` after copying its deferred state to
     * `tableColumns`, so this is the post-morph hook that actually runs when
     * the user presses "Apply columns".
     *
     * @param  array<int, array<string, mixed>>|null  $state
     */
    public function applyTableColumnManager(?array $state = null, bool $wasReordered = false): void
    {
        $this->baseApplyTableColumnManager($state, $wasReordered);
    }

    public function resetTableColumnManager(): void
    {
        $this->baseResetTableColumnManager();
    }

    public function updatedTableSearch(): void
    {
        $this->isTableLoaded = false;

        // CanSearchRecords::updatedTableSearch() (aliased above, since it's
        // pulled into InteractsWithTable from a nested trait) persists the
        // search term to the session and resets the page - both silently
        // dropped if this override doesn't call it. No queueHeaderScroll()
        // here unlike the other two triggers: yanking the page's scroll
        // position while the user is actively typing in the search box
        // would be disruptive, whereas a row count change is the whole
        // point of searching.
        $this->baseUpdatedTableSearch();
    }

    public function updatedTableFilters(): void
    {
        $this->isTableLoaded = false;
        $this->baseUpdatedTableFilters();
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

    /**
     * Sources enabled for this egg (via feature flags) that support the
     * current page's project type. An egg with no opt-in source feature flags
     * keeps Modrinth as its baseline; Plugin and Datapack pages may also have
     * their default CurseForge source unless the egg opts out.
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
     * Sources that can power a browsable catalog tab. Sources such as GitHub
     * Releases remain available for installed-file provenance and their
     * direct-tracking action, but must not produce an always-empty tab.
     *
     * @return array<int, ProjectSourceInterface>
     */
    protected function getCatalogSources(): array
    {
        return array_values(array_filter(
            $this->getAvailableSources(),
            static fn (ProjectSourceInterface $source): bool => $source->supportsSearch(),
        ));
    }

    /**
     * The source backing the currently active tab. When only one catalog
     * source is available, it is used regardless of the tab key, since the
     * tab is the source's own catalog label rather than a per-source key.
     */
    protected function getCurrentSource(): ?ProjectSourceInterface
    {
        $sources = $this->getCatalogSources();

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

    /**
     * Catalog tabs are ordered by ProjectSourceRegistry. The first configured
     * catalog source is the initial tab, matching the visible tab order.
     */
    public function getDefaultActiveTab(): string|int|null
    {
        $sources = $this->getCatalogSources();

        if (count($sources) > 1) {
            return $sources[0]->getKey()->value;
        }

        return array_key_first($this->getCachedTabs());
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
     * One tab per searchable source (when more than one is enabled for this
     * egg), plus the "Installed" tab with the cached scan's file count. A
     * source requiring setup is excluded by ProjectSourceRegistry, so no
     * unusable tab remains. When only one catalog source is available, its
     * label is shown instead of a misleading generic "All" tab.
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        $sources = $this->getCatalogSources();
        $tabs = [];

        if (count($sources) <= 1) {
            $tabs['all'] = Tab::make($sources[0]?->getLabel() ?? trans('pelican-minecraft-modrinth::strings.page.view_all'));
        } else {
            foreach ($sources as $source) {
                $tabs[$source->getKey()->value] = Tab::make($source->getLabel());
            }
        }

        $installedTab = Tab::make(trans('pelican-minecraft-modrinth::strings.page.view_installed'));
        if ($this->installedFilesCount !== null && $this->installedFilesCount >= 0) {
            $installedTab = $installedTab->badge($this->installedFilesCount);
        }

        $tabs['installed'] = $installedTab;

        return $tabs;
    }

    /**
     * Clamp stale/direct paginator state to a real page. LengthAwarePaginator
     * accepts a current page beyond its last page, which otherwise produces
     * an empty table with a misleading "0 to 0" summary.
     */
    protected function clampTablePage(int $page, int $total): int
    {
        $lastPage = max(1, (int) ceil($total / self::TABLE_PAGE_SIZE));

        return min(max(1, $page), $lastPage);
    }

    protected function synchronizeTablePage(int $page, int $total): int
    {
        $clampedPage = $this->clampTablePage($page, $total);

        if ($clampedPage !== $page) {
            // This runs from the records() callback itself. setPage() would
            // invoke updatedPaginators(), which deliberately returns this
            // table to deferred loading and discards the valid page we can
            // already render in this same response. Keep Livewire's public
            // paginator state in sync directly instead.
            $this->paginators[$this->getTablePaginationPageName()] = $clampedPage;
        }

        return $clampedPage;
    }

    /** @return array<int, array{source: string, project_id: string, project_slug: string, project_title: string, version_id: string, version_number: string, filename: string, installed_at: string}> */
    protected function getInstalledModsMetadata(): array
    {
        if ($this->installedModsMetadata === null) {
            $startedAt = $this->isModManagerTimingEnabled() ? microtime(true) : 0.0;

            /** @var Server $server */
            $server = Filament::getTenant();
            /** @var DaemonFileRepository $fileRepository */
            $fileRepository = app(DaemonFileRepository::class);

            $type = static::detectProjectType($server);
            $generation = CacheVersion::hydration($server);
            $typeKey = $type instanceof ProjectType ? $type->value : 'unknown';
            $cacheKey = "installed_metadata_display:v2:{$server->id}:{$typeKey}:{$generation}";
            $cached = Cache::get($cacheKey);
            $cacheHit = is_array($cached);
            $metadataStatus = 'cache';

            if ($cacheHit) {
                $this->installedModsMetadata = $cached;
            } else {
                $metadataResult = ModManager::getInstalledMetadataReadResult($server, $fileRepository, $type);
                $this->installedModsMetadata = $metadataResult->document->installedMods();
                $metadataStatus = $metadataResult->status->value;

                // Never turn a transient Wings/metadata read failure into an
                // hour of an apparently empty Installed tab. A valid empty
                // current/legacy document remains authoritative and cacheable.
                //
                // The generation stamp in $cacheKey already invalidates this
                // entry on every write (install/update/uninstall/scan - see
                // InstalledMetadataRepository::write()), so this TTL is only
                // a safety net for edits made outside the plugin (e.g. via
                // the file manager). "Rescan" (scan_mods, below) writes the
                // metadata document unconditionally and so doubles as a
                // manual refresh for that case.
                if ($metadataResult->isAuthoritative()) {
                    Cache::put($cacheKey, $this->installedModsMetadata, now()->addHour());
                }
            }

            if ($this->isModManagerTimingEnabled()) {
                Log::info('Mod manager timing', [
                    'stage' => 'installed_metadata',
                    'request_id' => $this->modManagerTimingRequestId,
                    'cache_hit' => $cacheHit,
                    'metadata_status' => $metadataStatus,
                    'started_after_ms' => $this->getModManagerTimingElapsedMs($startedAt),
                    'finished_after_ms' => $this->getModManagerTimingElapsedMs(),
                    'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                    'entries' => count($this->installedModsMetadata),
                ]);
            }
        }

        return $this->installedModsMetadata;
    }

    /** @return array{source: string, project_id: string, project_slug: string, project_title: string, version_id: string, version_number: string, filename: string, installed_at: string, author?: string}|null */
    protected function getInstalledMod(string $projectId, string $sourceKey = ''): ?array
    {
        $sourceKey = $sourceKey !== '' ? $sourceKey : ProjectSourceKey::Modrinth->value;
        if ($this->installedModsIndex === null) {
            $this->installedModsIndex = [];

            foreach ($this->getInstalledModsMetadata() as $mod) {
                $key = ($mod['source'] ?? ProjectSourceKey::Modrinth->value).':'.$mod['project_id'];
                $this->installedModsIndex[$key] = $mod;
            }
        }

        return $this->installedModsIndex[$sourceKey.':'.$projectId] ?? null;
    }

    protected function forgetInstalledModsMetadata(): void
    {
        $this->installedModsMetadata = null;
        $this->installedModsIndex = null;
    }

    protected function forgetVersionCaches(): void
    {
        $this->versionsCache = [];
        $this->latestVersionsCache = [];
    }

    protected function forgetVersionCache(string $cacheIndex): void
    {
        unset($this->versionsCache[$cacheIndex]);
        unset($this->latestVersionsCache[$cacheIndex]);
    }

    /** @return array<int, mixed> */
    protected function getCachedVersions(string $projectId, string $sourceKey): array
    {
        $cacheIndex = "$sourceKey:$projectId";

        if (!isset($this->versionsCache[$cacheIndex])) {
            $startedAt = $this->isModManagerTimingEnabled() ? microtime(true) : 0.0;

            /** @var Server $server */
            $server = Filament::getTenant();
            $type = static::detectProjectType($server);
            $source = app(ProjectSourceRegistry::class)->get(ProjectSourceKey::tryFrom($sourceKey) ?? ProjectSourceKey::Modrinth);

            $this->versionsCache[$cacheIndex] = ($source && $type) ? $source->getVersions($projectId, $server, $type) : [];

            if ($this->isModManagerTimingEnabled()) {
                $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
                $this->modManagerTimingVersionLookups++;
                $this->modManagerTimingVersionLookupDurationMs += $durationMs;

                Log::info('Mod manager timing', [
                    'stage' => 'record_version_lookup',
                    'request_id' => $this->modManagerTimingRequestId,
                    'source' => $sourceKey,
                    'project_id' => $projectId,
                    'started_after_ms' => $this->getModManagerTimingElapsedMs($startedAt),
                    'finished_after_ms' => $this->getModManagerTimingElapsedMs(),
                    'duration_ms' => $durationMs,
                    'versions_count' => count($this->versionsCache[$cacheIndex]),
                ]);
            }
        }

        return $this->versionsCache[$cacheIndex];
    }

    /** @return array<string, mixed>|null */
    protected function getCachedLatestVersion(string $projectId, string $sourceKey): ?array
    {
        $cacheIndex = "$sourceKey:$projectId";

        if (!array_key_exists($cacheIndex, $this->latestVersionsCache)) {
            $startedAt = $this->isModManagerTimingEnabled() ? microtime(true) : 0.0;
            $installedMod = $this->getInstalledMod($projectId, $sourceKey);

            /** @var Server $server */
            $server = Filament::getTenant();
            $type = static::detectProjectType($server);
            $result = ($installedMod !== null && $type !== null)
                ? app(VersionLookupCoordinator::class)->lookupInstalled([$installedMod], $server, $type)
                : null;

            $this->latestVersionsCache[$cacheIndex] = $result?->version($cacheIndex);
            if ($this->isModManagerTimingEnabled()) {
                $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
                $this->modManagerTimingVersionLookups++;
                $this->modManagerTimingVersionLookupDurationMs += $durationMs;

                Log::info('Mod manager timing', [
                    'stage' => 'record_version_lookup',
                    'request_id' => $this->modManagerTimingRequestId,
                    'source' => $sourceKey,
                    'project_id' => $projectId,
                    'started_after_ms' => $this->getModManagerTimingElapsedMs($startedAt),
                    'finished_after_ms' => $this->getModManagerTimingElapsedMs(),
                    'duration_ms' => $durationMs,
                    'versions_count' => $this->latestVersionsCache[$cacheIndex] === null ? 0 : 1,
                    'coordinated' => true,
                ]);
            }
        }

        return $this->latestVersionsCache[$cacheIndex];
    }

    /**
     * Whether the given project's latest-version lookup is still waiting on
     * a background revalidation queued by peekVisibleLatestVersions(). Only
     * ever true on the Installed tab; the catalog tab's warm path never
     * leaves an entry pending.
     */
    protected function isLatestVersionPending(string $projectId, string $sourceKey): bool
    {
        return isset($this->pendingLatestVersionKeys["$sourceKey:$projectId"]);
    }

    /**
     * Non-blocking counterpart to warmVisibleLatestVersions(), used by the
     * Installed tab's render path so a cold cache never blocks the
     * response. A cache hit (fresh or stale) is used immediately, same as
     * the blocking path; a miss queues a background revalidation and
     * leaves the entry out of latestVersionsCache entirely (see
     * isLatestVersionPending()) so it isn't mistaken for a confirmed
     * no-update result.
     *
     * @param  array<int, array<string, mixed>>  $records
     * @return bool Whether any of the given records is still pending.
     */
    protected function peekVisibleLatestVersions(array $records, Server $server, ProjectType $type): bool
    {
        $installedMods = [];

        foreach ($records as $record) {
            $projectId = $record['project_id'] ?? null;
            $sourceKey = $record['source'] ?? null;

            if (!is_string($projectId) || $projectId === '' || !is_string($sourceKey) || $sourceKey === '') {
                continue;
            }

            $cacheIndex = "$sourceKey:$projectId";
            if (array_key_exists($cacheIndex, $this->latestVersionsCache) && !isset($this->pendingLatestVersionKeys[$cacheIndex])) {
                continue;
            }

            $installedMod = $this->getInstalledMod($projectId, $sourceKey);
            if ($installedMod !== null) {
                $installedMods[$cacheIndex] = $installedMod;
            }
        }

        if ($installedMods === []) {
            return $this->pendingLatestVersionKeys !== [];
        }

        $startedAt = $this->isModManagerTimingEnabled() ? microtime(true) : 0.0;
        $result = app(VersionLookupCoordinator::class)->peekInstalled(array_values($installedMods), $server, $type);

        foreach (array_keys($installedMods) as $cacheIndex) {
            if ($result->isPending($cacheIndex)) {
                $this->pendingLatestVersionKeys[$cacheIndex] = true;

                continue;
            }

            unset($this->pendingLatestVersionKeys[$cacheIndex]);
            $this->latestVersionsCache[$cacheIndex] = $result->version($cacheIndex);
        }

        if ($this->isModManagerTimingEnabled()) {
            $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

            Log::info('Mod manager timing', [
                'stage' => 'record_version_lookup_peek',
                'request_id' => $this->modManagerTimingRequestId,
                'source' => 'coordinator',
                'started_after_ms' => $this->getModManagerTimingElapsedMs($startedAt),
                'finished_after_ms' => $this->getModManagerTimingElapsedMs(),
                'duration_ms' => $durationMs,
                'project_count' => count($installedMods),
                'resolved_count' => count($result->versions()),
                'unresolved_count' => count($result->unresolvedKeys()),
                'failed_count' => count($result->failures()),
                'pending_count' => count($result->pendingKeys()),
            ]);
        }

        return $this->pendingLatestVersionKeys !== [];
    }

    /** @param array<int, array<string, mixed>> $records */
    protected function warmVisibleLatestVersions(array $records, Server $server, ProjectType $type): void
    {
        $installedMods = [];

        foreach ($records as $record) {
            $projectId = $record['project_id'] ?? null;
            $sourceKey = $record['source'] ?? null;

            if (!is_string($projectId) || $projectId === '' || !is_string($sourceKey) || $sourceKey === '') {
                continue;
            }

            $cacheIndex = "$sourceKey:$projectId";
            if (array_key_exists($cacheIndex, $this->latestVersionsCache)) {
                continue;
            }

            $installedMod = $this->getInstalledMod($projectId, $sourceKey);
            if ($installedMod !== null) {
                $installedMods[$cacheIndex] = $installedMod;
            }
        }

        if ($installedMods === []) {
            return;
        }

        $startedAt = $this->isModManagerTimingEnabled() ? microtime(true) : 0.0;
        $result = app(VersionLookupCoordinator::class)->lookupInstalled(array_values($installedMods), $server, $type);

        foreach (array_keys($installedMods) as $cacheIndex) {
            $this->latestVersionsCache[$cacheIndex] = $result->version($cacheIndex);
        }

        if ($this->isModManagerTimingEnabled()) {
            $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
            $this->modManagerTimingVersionLookups += count($installedMods);
            $this->modManagerTimingVersionLookupDurationMs += $durationMs;

            Log::info('Mod manager timing', [
                'stage' => 'record_version_lookup_batch',
                'request_id' => $this->modManagerTimingRequestId,
                'source' => 'coordinator',
                'started_after_ms' => $this->getModManagerTimingElapsedMs($startedAt),
                'finished_after_ms' => $this->getModManagerTimingElapsedMs(),
                'duration_ms' => $durationMs,
                'project_count' => count($installedMods),
                'resolved_count' => count($result->versions()),
                'unresolved_count' => count($result->unresolvedKeys()),
                'failed_count' => count($result->failures()),
            ]);
        }
    }

    protected function getCachedDatapackWorldName(Server $server, DaemonFileRepository $fileRepository): string
    {
        if ($this->datapackWorldName === null) {
            $this->datapackWorldName = ModManager::getDatapackWorldName($server, $fileRepository);
        }

        return $this->datapackWorldName;
    }

    /**
     * Resolve a folder for display-only UI such as header actions. Mutating
     * operations continue to resolve the folder at execution time.
     */
    protected function getDisplayProjectFolder(
        Server $server,
        DaemonFileRepository $fileRepository,
        ProjectType $type,
    ): string {
        if ($type === ProjectType::Datapack) {
            return $this->getCachedDatapackWorldName($server, $fileRepository).'/datapacks';
        }

        return ModManager::getProjectFolder($server, $fileRepository, $type);
    }

    /**
     * @param  array<int, array{primary: bool, filename: string, url: string}>  $files
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
            ProjectSourceKey::CurseForge->value => 'https://www.curseforge.com/minecraft/'.match ($projectType) {
                ProjectType::Plugin->value => 'bukkit-plugins',
                ProjectType::Datapack->value => 'texture-packs',
                default => 'mc-mods',
            }."/{$slug}",
            ProjectSourceKey::Hangar->value => empty($record['author']) ? null : "https://hangar.papermc.io/{$record['author']}/{$slug}",
            ProjectSourceKey::GitHubReleases->value => "https://github.com/{$slug}",
            default => null,
        };
    }

    protected function canManageProjectOperation(Server $server, ProjectOperation $operation): bool
    {
        return app(ProjectOperationAuthorizer::class)->allows(user(), $server, $operation);
    }

    protected function canManageCurrentProjectOperation(ProjectOperation $operation): bool
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        return $this->canManageProjectOperation($server, $operation);
    }

    protected function authorizeProjectOperation(Server $server, ProjectOperation $operation): void
    {
        abort_unless($this->canManageProjectOperation($server, $operation), 403);
    }

    /**
     * @param  array<string, mixed>  $record
     * @param  array<string, mixed>  $versionData
     * @param  array<string, mixed>  $primaryFile
     * @param  array<string, mixed>|null  $installedMod
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
        $this->authorizeProjectOperation(
            $server,
            $installedMod === null ? ProjectOperation::Install : ProjectOperation::Update,
        );

        $safeNewFilename = $this->validateFilename($primaryFile['filename']);
        $oldFilename = $installedMod ? $this->validateFilename($installedMod['filename']) : null;

        $type = static::detectProjectType($server);
        if (!$type) {
            throw new Exception('Server does not support managed mods or plugins');
        }

        $sourceKey = ProjectSourceKey::tryFrom($record['source'] ?? '') ?? ProjectSourceKey::Modrinth;

        $folder = ModManager::getProjectFolder($server, $fileRepository, $type);

        $fileRepository->setServer($server)->pull($primaryFile['url'], $folder);

        $saved = ModManager::saveModMetadata(
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

                if ($installedMod && !ModManager::saveModMetadata(
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

        Cache::forget(ModManager::getHashScanCacheKey($server, $type));
        $this->setInstalledScanResult(null);
        $this->unknownFiles = array_values(
            array_filter($this->unknownFiles, fn (string $filename) => strtolower($filename) !== strtolower($safeNewFilename))
        );
    }

    /**
     * @param  array<string, mixed>  $record
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
     * Whether ->records()'s current (activeTab, page, search, filters,
     * sort) already has real data available with no upstream I/O required,
     * so ->deferLoading() can be skipped and this response can go out with
     * real records already in place instead of paying for the second
     * Livewire round trip (wire:init="loadTable") a deferred table always
     * costs.
     *
     * Deliberately always false for the Installed tab: unlike the catalog
     * tab, records()'s installed branch can still discover a missing scan
     * cache and dispatch a job (or show the queue-configuration warning).
     * hasWarmRecordsCache() can only see the longer-lived metadata display
     * cache, not that separate scan-result cache. Keeping the Installed tab
     * unconditionally deferred gives that state transition its own request;
     * the manager rejects sync/null queues, and the render itself remains
     * non-blocking through peekVisibleLatestVersions()/peekInstalled() and
     * pollEnrichment().
     */
    protected function hasWarmRecordsCache(): bool
    {
        if ($this->activeTab === 'installed') {
            return false;
        }

        /** @var Server $server */
        $server = Filament::getTenant();
        $type = static::detectProjectType($server);
        $currentSource = $this->getCurrentSource();

        if (!$type || !$currentSource || !$currentSource->isConfigured() || !$currentSource->supportsSearch()) {
            // records() resolves this to an empty paginator with no cache
            // lookup at all - nothing a deferred round trip would hide.
            return true;
        }

        $filterState = $this->tableFilters ?? [];
        $category = $filterState['catalog_category']['value'] ?? null;
        $environment = $filterState['catalog_environment']['value'] ?? null;

        return $currentSource->hasCachedSearch(
            $server,
            $type,
            (int) $this->getTablePage(),
            $this->getTableSearch(),
            [
                'sort' => $this->catalogSort,
                'category' => $category,
                'environment' => $currentSource->getKey() === ProjectSourceKey::Modrinth ? $environment : null,
            ],
        );
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
                    $perPage = self::TABLE_PAGE_SIZE;
                    $scanCacheKey = ModManager::getHashScanCacheKey($server, $type);
                    $scanResult = InstalledScanResult::fromCache(Cache::get($scanCacheKey));
                    $installedMods = $this->getInstalledModsMetadata();
                    $unknownFiles = $scanResult === null ? [] : $scanResult->unknownFiles;
                    $this->unknownFiles = $unknownFiles;
                    $this->setInstalledScanResult($scanResult);

                    $operations = app(InstalledOperationManager::class);
                    $scanState = $operations->state(
                        $server,
                        $type,
                        InstalledOperationManager::OPERATION_SCAN,
                    );

                    if ($scanResult === null && $scanState === null) {
                        $dispatch = $operations->dispatchScan($server, $type);
                        $scanState = $dispatch['state'];

                        if ($dispatch['reason'] === 'sync_queue' && !$this->operationQueueWarningShown) {
                            $this->operationQueueWarningShown = true;
                            Notification::make()
                                ->title(trans('pelican-minecraft-modrinth::strings.operations.queue_required'))
                                ->warning()
                                ->send();
                        }
                    }

                    if ($scanState !== null) {
                        $this->setInstalledOperationState($scanState);
                    } else {
                        $state = $this->refreshInstalledOperationState();
                        $this->pollInstalledOperations = $state === null
                            ? $scanResult === null && !$this->operationQueueWarningShown
                            : $this->shouldPollInstalledOperation($state);
                    }

                    if ($search) {
                        $searchLower = strtolower($search);
                        $installedMods = array_values(array_filter($installedMods, function (array $mod) use ($searchLower) {
                            return str_contains(strtolower($mod['project_title']), $searchLower)
                                || str_contains(strtolower($mod['project_slug']), $searchLower);
                        }));

                        $unknownFiles = array_values(array_filter($unknownFiles, fn (string $filename) => str_contains(strtolower($filename), $searchLower)));
                    }

                    // hydrateInstalled()/peekInstalled() group records by source.
                    // Reproduce that ordering before pagination, then hydrate only
                    // this page's records instead of every installed project on
                    // every request.
                    $installedBySource = [];
                    foreach ($installedMods as $installedMod) {
                        $sourceKey = $installedMod['source'] ?? ProjectSourceKey::Modrinth->value;
                        $installedBySource[$sourceKey][] = $installedMod;
                    }

                    $orderedInstalledMods = $installedBySource
                        ? array_merge(...array_values($installedBySource))
                        : [];
                    $totalCount = count($orderedInstalledMods) + count($unknownFiles);
                    $page = $this->synchronizeTablePage($page, $totalCount);
                    $offset = ($page - 1) * $perPage;
                    $pagedInstalledMods = array_slice($orderedInstalledMods, $offset, $perPage);

                    // Neither call below performs an upstream fetch: both are
                    // cache-only reads that queue a background revalidation on a
                    // miss instead of blocking the response (see SourceCache::
                    // swrDeferred()). pollEnrichment drives a self-terminating
                    // poll (see getHeaderActions()/EmbeddedTable wrapper) that
                    // reloads the table once the background fills land.
                    $enrichmentPending = false;

                    if ($pagedInstalledMods !== [] && $type !== null) {
                        $enrichmentPending = $this->peekVisibleLatestVersions($pagedInstalledMods, $server, $type);
                    }

                    $projects = $pagedInstalledMods
                        ? app(ProjectSourceRegistry::class)->peekInstalled($pagedInstalledMods, $server)
                        : [];

                    foreach ($projects as $project) {
                        if ($project['enrichment_pending'] ?? false) {
                            $enrichmentPending = true;

                            break;
                        }
                    }

                    // A sync/null queue cannot complete a deferred metadata
                    // fill, so polling it would only repeat the same cache
                    // reads and table render indefinitely.
                    $this->pollEnrichment = $enrichmentPending && $operations->supportsAsyncDispatch();

                    $unknownOffset = max(0, $offset - count($orderedInstalledMods));
                    $remainingSlots = $perPage - count($pagedInstalledMods);
                    foreach (array_slice($unknownFiles, $unknownOffset, $remainingSlots) as $filename) {
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

                    return new LengthAwarePaginator($projects, $totalCount, $perPage, $page);
                }

                $currentSource = $this->getCurrentSource();

                $catalogStartedAt = $this->isModManagerTimingEnabled() ? microtime(true) : 0.0;

                if (!$type || !$currentSource || !$currentSource->isConfigured() || !$currentSource->supportsSearch()) {
                    return new LengthAwarePaginator([], 0, self::TABLE_PAGE_SIZE, $this->synchronizeTablePage($page, 0));
                }

                $filterState = $this->tableFilters ?? [];
                $category = $filterState['catalog_category']['value'] ?? null;
                $environment = $filterState['catalog_environment']['value'] ?? null;
                $sortOption = $this->catalogSort;

                $searchCatalog = fn (int $catalogPage): array => $currentSource->search(
                    $server,
                    $type,
                    $catalogPage,
                    $search,
                    [
                        'sort' => $sortOption,
                        'category' => $category,
                        'environment' => $currentSource->getKey() === ProjectSourceKey::Modrinth ? $environment : null,
                    ],
                );
                $requestedPage = $page;
                $response = $searchCatalog($page);
                $page = $this->synchronizeTablePage($page, (int) $response['total_hits']);

                if ($page !== $requestedPage && $response['total_hits'] > 0) {
                    $response = $searchCatalog($page);
                }

                if ($this->isModManagerTimingEnabled()) {
                    Log::info('Mod manager timing', [
                        'stage' => 'catalog_records',
                        'request_id' => $this->modManagerTimingRequestId,
                        'source' => $currentSource->getKey()->value,
                        'started_after_ms' => $this->getModManagerTimingElapsedMs($catalogStartedAt),
                        'finished_after_ms' => $this->getModManagerTimingElapsedMs(),
                        'duration_ms' => (int) round((microtime(true) - $catalogStartedAt) * 1000),
                        'hits' => count($response['hits']),
                    ]);
                }

                $hits = array_map(function (array $hit) use ($currentSource) {
                    $hit['source'] = $currentSource->getKey()->value;

                    return $hit;
                }, $response['hits']);

                $this->warmVisibleLatestVersions($hits, $server, $type);

                return new LengthAwarePaginator($hits, $response['total_hits'], self::TABLE_PAGE_SIZE, $page);
            })
            // Render the page shell immediately, unless the current request's
            // records are already cached (fresh or stale) with no upstream
            // fetch required - in which case skip the deferred round trip
            // entirely and render real records synchronously. See
            // hasWarmRecordsCache() for what "warm" means per tab, and why
            // the Installed tab is deliberately excluded from this check.
            ->deferLoading(fn (): bool => !$this->hasWarmRecordsCache())
            ->paginated([self::TABLE_PAGE_SIZE])
            // Category labels can be long (for example, "Armor, Tools, and
            // Weapons"), so retain a wider filters panel for the two real filters.
            ->filtersFormWidth(Width::Medium)
            ->filters([
                SelectFilter::make('catalog_category')
                    ->label(trans('pelican-minecraft-modrinth::strings.table.filters.category'))
                    ->options(fn () => $this->getCatalogCategoryOptions())
                    ->visible(fn () => $this->activeTab !== 'installed' && $this->getCatalogCategoryOptions() !== []),
                SelectFilter::make('catalog_environment')
                    ->label(trans('pelican-minecraft-modrinth::strings.table.filters.environment'))
                    ->options([
                        'server' => trans('pelican-minecraft-modrinth::strings.table.filters.environment_server'),
                        'client' => trans('pelican-minecraft-modrinth::strings.table.filters.environment_client'),
                    ])
                    ->visible(fn () => $this->activeTab !== 'installed' && $this->getCurrentSource()?->getKey() === ProjectSourceKey::Modrinth),
            ])
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
                    ->label('')
                    // ImageColumn omits its <img> when the state is blank. A
                    // local SVG keeps the common placeholder structure without
                    // an external request or visible "No image" text.
                    ->defaultImageUrl(ProjectIconUrl::placeholderDataUri())
                    ->alignCenter()
                    // The client-side stale preview updates only values in the
                    // real Filament cell. Keep this selector independent of
                    // Filament's generated HTML below the cell.
                    ->extraCellAttributes(['data-mmr-swr-cell' => 'icon', 'class' => 'mmr-project-icon-cell'])
                    ->extraImgAttributes([
                        'data-mmr-project-icon' => 'true',
                        'loading' => 'lazy',
                        'decoding' => 'async',
                        'onerror' => ProjectIconUrl::fallbackHandler(),
                    ]),
                TextColumn::make('title')
                    ->label(trans('pelican-minecraft-modrinth::strings.table.columns.title'))
                    ->searchable()
                    ->wrap()
                    ->lineClamp(1)
                    ->extraCellAttributes(['data-mmr-swr-cell' => 'title'])
                    ->description(function (array $record): ?string {
                        if ($record['untracked'] ?? false) {
                            return trans('pelican-minecraft-modrinth::strings.badges.untracked');
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
                    ->extraCellAttributes(['data-mmr-swr-cell' => 'source'])
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
                    ->label(trans('pelican-minecraft-modrinth::strings.table.columns.author'))
                    ->url(fn (array $record, $state) => (($record['source'] ?? null) === ProjectSourceKey::Modrinth->value && $state) ? "https://modrinth.com/user/$state" : null, true)
                    ->extraCellAttributes(['data-mmr-swr-cell' => 'author'])
                    ->toggleable(),
                TextColumn::make('downloads')
                    ->label(trans('pelican-minecraft-modrinth::strings.table.columns.downloads'))
                    ->icon('tabler-download')
                    ->numeric()
                    ->extraCellAttributes(['data-mmr-swr-cell' => 'downloads'])
                    ->toggleable(),
                TextColumn::make('date_modified')
                    ->label(trans('pelican-minecraft-modrinth::strings.table.columns.date_modified'))
                    ->icon('tabler-calendar')
                    ->formatStateUsing(fn ($state) => $state ? Carbon::parse($state, 'UTC')->diffForHumans() : '')
                    ->tooltip(fn ($state) => $state ? Carbon::parse($state, 'UTC')->timezone(user()->timezone ?? 'UTC')->format($table->getDefaultDateTimeDisplayFormat()) : '')
                    ->extraCellAttributes(['data-mmr-swr-cell' => 'date_modified'])
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
                    ->extraAttributes([
                        'class' => 'mx-0.5',
                        'data-mmr-swr-row-action' => 'versions',
                        'data-mmr-swr-row-action-color' => 'info',
                    ])
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
                                    ->authorize(fn () => $this->canManageCurrentProjectOperation(
                                        $installedMod === null ? ProjectOperation::Install : ProjectOperation::Update,
                                    ))
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

                                            $this->forgetInstalledModsMetadata();
                                            $this->forgetVersionCaches();
                                            $this->flushCachedTableRecords();

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

                                            $this->forgetInstalledModsMetadata();
                                            $this->forgetVersionCaches();
                                            $this->flushCachedTableRecords();

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
                    ->extraAttributes([
                        'class' => 'mx-0.5',
                        'data-mmr-swr-row-action' => 'install_latest',
                        'data-mmr-swr-row-action-color' => 'success',
                    ])
                    ->icon('tabler-download')
                    ->color('success')
                    ->tooltip(trans('pelican-minecraft-modrinth::strings.actions.install_latest'))
                    ->authorize(fn (): bool => $this->canManageCurrentProjectOperation(ProjectOperation::Install))
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

                            $this->forgetInstalledModsMetadata();
                            $this->forgetVersionCaches();

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

                            $this->forgetInstalledModsMetadata();
                            $this->forgetVersionCaches();

                            Notification::make()
                                ->title(trans('pelican-minecraft-modrinth::strings.notifications.install_failed'))
                                ->body(trans('pelican-minecraft-modrinth::strings.notifications.install_failed_body'))
                                ->danger()
                                ->send();
                        }
                    }),
                Action::make('update')
                    ->iconButton()
                    ->extraAttributes([
                        'class' => 'mx-0.5',
                        'data-mmr-swr-row-action' => 'update',
                        'data-mmr-swr-row-action-color' => 'warning',
                    ])
                    ->icon('tabler-refresh')
                    ->color('warning')
                    ->tooltip(trans('pelican-minecraft-modrinth::strings.actions.update'))
                    ->authorize(fn (): bool => $this->canManageCurrentProjectOperation(ProjectOperation::Update))
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

                        // The update badge is still being resolved in the
                        // background (see peekVisibleLatestVersions()). Default
                        // to "installed" (see that action's visible()) rather
                        // than claiming no update is available.
                        if ($this->isLatestVersionPending($record['project_id'], $sourceKey)) {
                            return false;
                        }

                        $latestVersion = $this->getCachedLatestVersion($record['project_id'], $sourceKey);

                        if ($latestVersion === null) {
                            return false;
                        }

                        return $installedMod['version_id'] !== ($latestVersion['id'] ?? null);
                    })
                    ->requiresConfirmation()
                    ->modalHeading(trans('pelican-minecraft-modrinth::strings.modals.update_heading'))
                    ->modalDescription(function (array $record) {
                        $sourceKey = $record['source'] ?? ProjectSourceKey::Modrinth->value;
                        $installedMod = $this->getInstalledMod($record['project_id'], $sourceKey);
                        $latestVersion = $this->getCachedLatestVersion($record['project_id'], $sourceKey);

                        return trans('pelican-minecraft-modrinth::strings.modals.update_description', [
                            'old_version' => $installedMod['version_number'] ?? 'unknown',
                            'new_version' => $latestVersion['version_number'] ?? 'unknown',
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

                            $latestVersion = $this->getCachedLatestVersion($record['project_id'], $sourceKey->value);
                            if ($latestVersion === null) {
                                $versions = $source->getVersions($record['project_id'], $server, $type);
                                if (empty($versions)) {
                                    throw new Exception('No compatible versions found');
                                }

                                $latestVersion = $versions[0];
                            }

                            if (!isset($latestVersion['id'], $latestVersion['version_number'], $latestVersion['files'])) {
                                throw new Exception('Invalid version data structure');
                            }

                            $primaryFile = $this->getPrimaryFile($latestVersion['files']);

                            if (!$primaryFile) {
                                throw new Exception('No downloadable file found');
                            }

                            $this->performInstallOrUpdate($server, $fileRepository, $record, $latestVersion, $primaryFile, $installedMod);

                            $this->forgetInstalledModsMetadata();
                            $this->forgetVersionCaches();

                            Notification::make()
                                ->title(trans('pelican-minecraft-modrinth::strings.notifications.update_success'))
                                ->body(trans('pelican-minecraft-modrinth::strings.notifications.update_success_body', [
                                    'version' => $latestVersion['version_number'],
                                ]))
                                ->success()
                                ->send();
                        } catch (Exception $exception) {
                            report($exception);

                            $this->forgetInstalledModsMetadata();
                            $this->forgetVersionCaches();

                            Notification::make()
                                ->title(trans('pelican-minecraft-modrinth::strings.notifications.update_failed'))
                                ->body(trans('pelican-minecraft-modrinth::strings.notifications.update_failed_body'))
                                ->danger()
                                ->send();
                        }
                    }),
                Action::make('installed')
                    ->iconButton()
                    ->extraAttributes([
                        'class' => 'mx-0.5',
                        'data-mmr-swr-row-action' => 'installed',
                        'data-mmr-swr-row-action-color' => 'success',
                    ])
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

                        // Default to "installed" while the update badge is
                        // still resolving in the background - see the
                        // update action's visible(). No update is shown as
                        // available until it's actually confirmed, and the
                        // row corrects itself once pollEnrichment reloads
                        // the table.
                        if ($this->isLatestVersionPending($record['project_id'], $sourceKey)) {
                            return true;
                        }

                        $latestVersion = $this->getCachedLatestVersion($record['project_id'], $sourceKey);

                        if ($latestVersion === null) {
                            return true;
                        }

                        return $installedMod['version_id'] === ($latestVersion['id'] ?? null);
                    }),
                Action::make('uninstall')
                    ->iconButton()
                    ->extraAttributes([
                        'class' => 'mx-0.5',
                        'data-mmr-swr-row-action' => 'uninstall',
                        'data-mmr-swr-row-action-color' => 'danger',
                    ])
                    ->icon('tabler-trash')
                    ->color('danger')
                    ->tooltip(trans('pelican-minecraft-modrinth::strings.actions.uninstall'))
                    ->authorize(fn (): bool => $this->canManageCurrentProjectOperation(ProjectOperation::Delete))
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
                            $this->authorizeProjectOperation($server, ProjectOperation::Delete);

                            $safeFilename = $this->getUninstallFilename($record);

                            $type = static::detectProjectType($server);
                            if (!$type) {
                                throw new Exception('Server does not support managed mods or plugins');
                            }

                            $folder = ModManager::getProjectFolder($server, $fileRepository, $type);

                            Http::daemon($server->node)
                                ->post("/api/servers/{$server->uuid}/files/delete", [
                                    'root' => '/',
                                    'files' => [$folder.'/'.$safeFilename],
                                ])
                                ->throw();

                            Cache::forget(ModManager::getHashScanCacheKey($server, $type));
                            $this->setInstalledScanResult(null);
                            $this->unknownFiles = array_values(
                                array_filter($this->unknownFiles, fn (string $filename) => strtolower($filename) !== strtolower($safeFilename))
                            );

                            $sourceKey = ProjectSourceKey::tryFrom($record['source'] ?? '') ?? ProjectSourceKey::Modrinth;

                            $metadataRemoved = true;
                            if (!empty($record['project_id'])) {
                                $metadataRemoved = ModManager::removeModMetadata($server, $fileRepository, $record['project_id'], $type, $sourceKey);
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

                                $this->forgetVersionCache("{$sourceKey->value}:{$record['project_id']}");
                            } else {
                                $this->forgetInstalledModsMetadata();
                                $this->forgetVersionCaches();
                            }

                            if ($this->activeTab === 'installed') {
                                $this->flushCachedTableRecords();
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

                            $this->forgetInstalledModsMetadata();
                            $this->forgetVersionCaches();

                            if ($this->activeTab === 'installed') {
                                $this->flushCachedTableRecords();
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

    /** @return array<string, string> */
    protected function getCatalogCategoryOptions(): array
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        if ($this->getCurrentSource()?->getKey() === ProjectSourceKey::CurseForge
            && static::detectProjectType($server) === ProjectType::Datapack) {
            // CurseForge's Datapack catalog always searches its dedicated Data
            // Packs category. Offering a second category selector here would
            // be misleading because it intentionally cannot override that.
            return [];
        }

        return match ($this->getCurrentSource()?->getKey()) {
            ProjectSourceKey::Modrinth => ['adventure' => 'Adventure', 'cursed' => 'Cursed', 'decoration' => 'Decoration', 'economy' => 'Economy', 'equipment' => 'Equipment', 'food' => 'Food', 'magic' => 'Magic', 'optimization' => 'Optimization', 'social' => 'Social', 'technology' => 'Technology', 'utility' => 'Utility', 'worldgen' => 'World Generation'],
            ProjectSourceKey::CurseForge => ['406' => 'Technology', '407' => 'Storage', '408' => 'Cosmetic', '409' => 'Ores and Resources', '410' => 'Armor, Tools, and Weapons', '412' => 'Miscellaneous', '413' => 'Server Utility', '414' => 'Food', '415' => 'Energy', '416' => 'Farming', '417' => 'Transport', '419' => 'Magic'],
            default => [],
        };
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
        $folder = $this->getDisplayProjectFolder($server, $fileRepository, $type);

        $githubSource = app(ProjectSourceRegistry::class)->get(ProjectSourceKey::GitHubReleases);
        $availableSourceKeys = array_map(fn (ProjectSourceInterface $source) => $source->getKey()->value, $this->getAvailableSources());
        $githubAvailable = $githubSource
            && $githubSource->supportsProjectType($type)
            && in_array(ProjectSourceKey::GitHubReleases->value, $availableSourceKeys, true);

        return [
            Action::make('open_folder')
                ->label(fn () => trans('pelican-minecraft-modrinth::strings.page.open_folder', ['folder' => $folder]))
                ->tooltip(fn () => trans('pelican-minecraft-modrinth::strings.page.open_folder', ['folder' => $folder]))
                ->icon('tabler-folder-open')
                ->url(fn () => ListFiles::getUrl(['path' => $folder]), true),
            Action::make('track_github_repo')
                ->label(trans('pelican-minecraft-modrinth::strings.actions.track_github_repo'))
                ->icon('tabler-brand-github')
                ->authorize(fn (): bool => $this->canManageProjectOperation($server, ProjectOperation::Install))
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

                        $this->forgetInstalledModsMetadata();
                        $this->forgetVersionCaches();
                        $this->flushCachedTableRecords();

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
                ->visible(fn () => $githubAvailable && $this->canManageProjectOperation($server, ProjectOperation::Install)),
            Action::make('update_all')
                ->label(fn () => trans(match ($type) {
                    ProjectType::Plugin => 'pelican-minecraft-modrinth::strings.actions.update_all_plugins',
                    ProjectType::Datapack => 'pelican-minecraft-modrinth::strings.actions.update_all_datapacks',
                    default => 'pelican-minecraft-modrinth::strings.actions.update_all_mods',
                }))
                ->icon('tabler-download')
                ->color('warning')
                ->requiresConfirmation()
                ->authorize(fn (): bool => $this->canManageProjectOperation($server, ProjectOperation::Update))
                ->action(function () use ($server, $type) {
                    $this->authorizeProjectOperation($server, ProjectOperation::Update);

                    $dispatch = app(InstalledOperationManager::class)->dispatchBulkUpdate($server, $type);
                    $this->notifyInstalledOperationDispatched($dispatch);
                })
                ->visible(fn () => static::detectProjectType($server) !== null
                    && $this->activeTab === 'installed'
                    && $this->canManageProjectOperation($server, ProjectOperation::Update)),
            Action::make('scan_mods')
                ->label(fn () => $this->getRescanActionLabel($type))
                ->tooltip(fn () => $this->getRescanActionLabel($type))
                ->icon('tabler-search')
                ->action(function () use ($server, $type) {
                    $dispatch = app(InstalledOperationManager::class)->dispatchScan($server, $type, force: true);
                    $this->notifyInstalledOperationDispatched($dispatch);
                })
                ->visible(fn () => static::detectProjectType($server) !== null),
        ];
    }

    public function content(Schema $schema): Schema
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        $type = static::detectProjectType($server);

        if ($type === null) {
            // Only reachable via needsManualEggSetup() - canAccess() already
            // guarantees that when detectProjectType() is null.
            return $this->eggManualSetupContent($schema, $server);
        }

        return $schema
            ->components([
                Grid::make($type === ProjectType::Datapack ? 4 : 3)
                    ->extraAttributes(['class' => 'mmr-page-header'])
                    ->schema([
                        TextEntry::make('minecraft_version')
                            ->label(trans('pelican-minecraft-modrinth::strings.page.minecraft_version'))
                            ->state(fn () => ModManager::getMinecraftVersion($server) ?? trans('pelican-minecraft-modrinth::strings.page.unknown'))
                            ->badge()
                            ->size(TextSize::Large),
                        TextEntry::make('world')
                            ->label(trans('pelican-minecraft-modrinth::strings.page.world'))
                            ->state(fn (DaemonFileRepository $fileRepository) => $this->getCachedDatapackWorldName($server, $fileRepository))
                            ->badge()
                            ->size(TextSize::Large)
                            ->visible(fn () => $type === ProjectType::Datapack),
                        TextEntry::make('loader')
                            ->label(trans('pelican-minecraft-modrinth::strings.page.loader'))
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
                            // Stage 8 diagnostic (依頼 I): says which detection
                            // tier actually decided the type/loader shown
                            // here, so a wrong result can be traced back to
                            // "the egg's own explicit tags" vs "a profile
                            // database match" vs "a manual override" without
                            // reading logs.
                            ->tooltip(fn () => trans('pelican-minecraft-modrinth::strings.page.resolved_by', ['source' => $this->eggResolutionSourceLabel($server)]))
                            ->badge()
                            ->size(TextSize::Large)
                            ->extraAttributes(['class' => 'mcloader-badge']),
                        TextEntry::make('installed')
                            // $type is non-null for the rest of this method
                            // (see the early return above), so unlike
                            // getNavigationLabel()'s/getExternalProjectUrl()'s
                            // own $type?-> uses, this one is provably dead
                            // defensiveness - confirmed by PHPStan flagging
                            // it once that guard was added.
                            ->label(fn () => trans('pelican-minecraft-modrinth::strings.page.installed', ['type' => $type->getLabel()]))
                            ->state(fn () => match (true) {
                                $this->installedFilesCount === null => '…',
                                $this->installedFilesCount < 0 => trans('pelican-minecraft-modrinth::strings.page.unknown'),
                                default => $this->installedFilesCount,
                            })
                            ->badge()
                            ->size(TextSize::Large),
                    ]),
                $this->getTabsContentComponent(),
                Section::make()
                    ->extraAttributes(fn () => $this->installedOperationStatusExtraAttributes())
                    ->schema([
                        TextEntry::make('installed_operation_status')
                            ->hiddenLabel()
                            ->state(fn () => $this->installedOperationStatus())
                            // Mirrors installedOperationIsActive()'s split: the
                            // spinning loader belongs only to states that are
                            // genuinely still in flight. The terminal states get
                            // an icon that reads as an outcome instead, so a
                            // finished scan no longer looks like a running one.
                            ->icon(fn () => match ($this->getInstalledOperationDisplayPayload()['status'] ?? null) {
                                InstalledOperationState::STATUS_COMPLETED => 'tabler-check',
                                InstalledOperationState::STATUS_FAILED => 'tabler-alert-triangle',
                                default => $this->operationQueueWarningShown
                                    ? 'tabler-alert-triangle'
                                    : 'tabler-loader-2',
                            })
                            ->badge()
                            ->color(fn () => match ($this->getInstalledOperationDisplayPayload()['status'] ?? null) {
                                InstalledOperationState::STATUS_RUNNING => 'info',
                                InstalledOperationState::STATUS_COMPLETED => 'success',
                                InstalledOperationState::STATUS_FAILED => 'danger',
                                default => $this->operationQueueWarningShown ? 'danger' : 'gray',
                            })
                            // TextEntry has no dedicated hook for extra icon
                            // attributes (unlike ImageColumn's
                            // extraImgAttributes()) - Filament's own
                            // generate_icon_html() puts the "fi-icon" class
                            // directly on the rendered <svg>, so scoping the
                            // spin animation through this wrapper class and
                            // a descendant selector (like the existing
                            // .mcloader-badge rule below) reaches it without
                            // needing that hook.
                            ->extraAttributes(fn () => $this->installedOperationIsActive()
                                ? ['class' => 'mmr-installed-operation-spinning']
                                : []),
                    ])
                    // Bulk-update progress remains useful in the page body.
                    // A scan uses this same position, but only while the
                    // Installed tab is open (including its brief success
                    // outcome); catalog tabs stay free of scan state.
                    ->visible(fn () => $this->shouldShowInstalledOperationStatus()),
                Group::make([
                    EmbeddedTable::make(),
                ])->extraAttributes(fn () => array_merge([
                    'class' => 'mmr-table-scroll-ctn',
                    'data-mmr-swr-scope' => json_encode([
                        'user_id' => (string) user()->getKey(),
                        'server_id' => (string) $server->getKey(),
                        // Same as the 'installed' TextEntry's label above:
                        // $type is non-null for the rest of this method.
                        'project_type' => $type->value,
                    ], JSON_THROW_ON_ERROR),
                    // This class is what the injected stylesheet's flex layout
                    // and the table-layout partial both hang off, so the table
                    // fills the remaining viewport and the paginator stays
                    // put. Deliberately nothing is queued from PHP for it:
                    // the space above the table depends on the topbar, the
                    // sidebar mode and this page's own header wrapping, none
                    // of which change when a table updates, and re-measuring
                    // per update is what previously coupled the layout to
                    // render timing. A ResizeObserver covers the cases that
                    // do change it.
                ], $this->pollInstalledOperations
                    // Keep observing scan/bulk-update state even though scan
                    // status no longer renders a page-body component.
                    ? ['wire:poll.2s' => 'pollInstalledOperation']
                    : [], $this->activeTab === 'installed' && $this->pollEnrichment
                    // Independent of pollInstalledOperations: this fires only
                    // while a background icon/downloads/date_modified or
                    // update-badge fetch is still outstanding (see
                    // peekVisibleLatestVersions()/ProjectSourceRegistry::
                    // peekInstalled()), and stops on its own once records()
                    // finds nothing left pending.
                    ? ['wire:poll.5s' => 'pollEnrichment']
                    : [])),
            ]);
    }

    /**
     * Stage 8's GUI fallback: rendered instead of the normal catalog/
     * Installed content when EggProfileResolver could not place this
     * server's egg automatically but judged it plausibly Minecraft-related
     * (see needsManualEggSetup()/canAccess()). Whoever can edit gets an
     * inline form scoped to just this server's egg (egg_id is fixed, not
     * user-selectable - contrast the admin settings screen's version of
     * this same schema, which lets an admin pick any egg); everyone else
     * sees a read-only notice, with a link to the settings screen for an
     * admin whose edit check itself failed (see canEditEggProfile()).
     */
    protected function eggManualSetupContent(Schema $schema, Server $server): Schema
    {
        $canEdit = $this->canEditEggProfile($server);
        $isAdmin = (bool) user()?->isAdmin();

        $noticeEntries = [
            TextEntry::make('egg_manual_setup_notice')
                ->hiddenLabel()
                ->state(trans('pelican-minecraft-modrinth::strings.page.egg_manual_setup_heading').' — '.trans('pelican-minecraft-modrinth::strings.page.egg_manual_setup_description'))
                ->icon('tabler-alert-triangle')
                ->color('warning'),
        ];

        if (!$canEdit && !$isAdmin) {
            $noticeEntries[] = TextEntry::make('egg_manual_setup_readonly')
                ->hiddenLabel()
                ->state(trans('pelican-minecraft-modrinth::strings.page.egg_manual_setup_readonly'))
                ->color('gray');
        }

        $actions = [];

        if ($canEdit) {
            $actions[] = Action::make('configure_egg_profile')
                ->label(trans('pelican-minecraft-modrinth::strings.settings.egg_profiles'))
                ->color('primary')
                ->icon('tabler-egg')
                ->modalHeading(trans('pelican-minecraft-modrinth::strings.settings.egg_profiles_confirmation_heading'))
                ->modalDescription(trans('pelican-minecraft-modrinth::strings.page.egg_manual_setup_form_warning'))
                ->schema(ModManagerPlugin::eggProfileFormSchema(includeEggSelect: false))
                ->fillForm(function () use ($server): array {
                    $server->loadMissing('egg');

                    return ModManagerPlugin::eggProfileDefaults($server->egg);
                })
                ->action(function (array $data) use ($server): void {
                    $server->loadMissing('egg');

                    if ($server->egg === null) {
                        return;
                    }

                    $data['egg_id'] = $server->egg->getKey();
                    ModManagerPlugin::saveEggProfile($data);

                    // The resolver memoizes per (server, egg) for the life
                    // of this request - without clearing it, the very next
                    // read (this same Livewire action's re-render) would
                    // still see the pre-save result.
                    EggProfileResolver::clear();
                });
        } elseif ($isAdmin) {
            // canEdit failed for an admin only when the toggle is on and
            // this specific server falls outside their node access (see
            // canEditEggProfile()) - the settings screen's own version of
            // this form isn't server-scoped, so it works regardless.
            $actions[] = Action::make('goto_egg_settings')
                ->label(trans('pelican-minecraft-modrinth::strings.page.egg_manual_setup_admin_action'))
                ->color('gray')
                ->icon('tabler-settings')
                ->url(fn () => PluginResource::getUrl('index', panel: 'admin'));
        }

        return $schema->components([
            Section::make()
                ->schema([
                    ...$noticeEntries,
                    ...($actions ? [Actions::make($actions)] : []),
                ]),
        ]);
    }

    protected function canEditEggProfile(Server $server): bool
    {
        if ((bool) config('pelican-minecraft-modrinth.allow_user_egg_profile_edit', false)) {
            return (bool) user()?->can(SubuserPermission::StartupUpdate, $server);
        }

        return (bool) user()?->isAdmin();
    }

    /**
     * Stage 8 diagnostic (see the loader TextEntry's tooltip in content()).
     * Checked independently of EggProfileResolver's own memoized result so
     * this never triggers a profile-database/manual-table lookup for the
     * (overwhelmingly common) case where the egg's own explicit features/
     * tags already answered the question - matching ProjectType::
     * fromServer()'s/MinecraftLoader::fromServer()'s own explicit-first
     * short-circuit exactly.
     */
    protected function eggResolutionSourceLabel(Server $server): string
    {
        $server->loadMissing('egg');

        if (ProjectType::fromServerExplicit($server) !== null || MinecraftLoader::fromTags($server->egg->tags ?? []) !== null) {
            return trans('pelican-minecraft-modrinth::strings.page.resolved_by_explicit');
        }

        if (!(bool) config('pelican-minecraft-modrinth.egg_autodetect_enabled', true)) {
            return trans('pelican-minecraft-modrinth::strings.page.resolved_by_none');
        }

        $source = EggProfileResolver::resolve($server)->source;

        return trans('pelican-minecraft-modrinth::strings.page.resolved_by_'.$source);
    }

    protected function getRescanActionLabel(?ProjectType $type): string
    {
        return trans(match ($type) {
            ProjectType::Plugin => 'pelican-minecraft-modrinth::strings.actions.rescan_plugins_for_updates',
            ProjectType::Datapack => 'pelican-minecraft-modrinth::strings.actions.rescan_datapacks_for_updates',
            default => 'pelican-minecraft-modrinth::strings.actions.rescan_mods_for_updates',
        });
    }

    protected function getInstalledOperationFingerprint(InstalledOperationState $state): string
    {
        return $state->operation.':'.($state->finishedAt ?? '');
    }

    protected function markInstalledOperationHandled(InstalledOperationState $state): void
    {
        $this->handledInstalledOperation = $this->getInstalledOperationFingerprint($state);
        $this->pollInstalledOperations = false;
    }

    protected function getInstalledScanFingerprint(InstalledOperationState $state): string
    {
        return implode(':', [
            $state->operation,
            $state->serverId,
            $state->projectType->value,
            $state->queuedAt,
        ]);
    }

    protected function setInstalledOperationState(?InstalledOperationState $state): void
    {
        $this->installedOperation = $state?->toCachePayload();
        $this->pollInstalledOperations = $this->shouldPollInstalledOperation($state);

        if ($state?->operation !== InstalledOperationManager::OPERATION_SCAN || !$state->isActive()) {
            return;
        }

        // A new scan supersedes any prior short-lived success outcome. Only
        // remember it when Installed is actually open; a catalog visitor
        // should never inherit scan UI after changing tabs.
        $this->installedScanCompletion = null;
        $this->observedInstalledScan = $this->activeTab === 'installed'
            ? $this->getInstalledScanFingerprint($state)
            : null;
    }

    protected function rememberInstalledScanCompletion(InstalledOperationState $state): void
    {
        if ($state->operation !== InstalledOperationManager::OPERATION_SCAN
            || $state->status !== InstalledOperationState::STATUS_COMPLETED
            || $this->activeTab !== 'installed'
            || $this->observedInstalledScan !== $this->getInstalledScanFingerprint($state)) {
            return;
        }

        $this->installedScanCompletion = $state->toCachePayload();
    }

    public function pollInstalledOperation(): void
    {
        $previousOperation = $this->installedOperation;
        $state = $this->refreshInstalledOperationState();

        if ($state === null) {
            $this->pollInstalledOperations = false;

            return;
        }

        if (!$state->isFinished()) {
            $this->pollInstalledOperations = true;

            // Avoid rebuilding the complete component (including catalog cards
            // and image nodes) when this poll cannot change visible state.
            // shouldSkipInstalledOperationPollRender() retains normal renders
            // for Installed scan progress, bulk progress, and completion.
            if ($this->shouldSkipInstalledOperationPollRender($previousOperation, $state)) {
                $this->skipRender();
            }

            return;
        }

        $fingerprint = $this->getInstalledOperationFingerprint($state);
        if ($this->handledInstalledOperation === $fingerprint) {
            return;
        }

        $this->markInstalledOperationHandled($state);
        $this->rememberInstalledScanCompletion($state);
        // Catalog records do not read the Installed scan cache themselves.
        // Refresh it explicitly so the header badge changes in this same
        // Livewire poll response, without requiring a page reload or an
        // Installed-tab visit.
        $this->refreshInstalledScanDataReady();
        $this->forgetInstalledModsMetadata();
        $this->forgetVersionCaches();
        // A poll response re-renders the component already. Invalidate only
        // the records cache so the table sees the new scan data while keeping
        // the active page, search term, and catalog filters intact.
        $this->flushCachedTableRecords();
        $this->notifyInstalledOperationFinished($state);

        /** @var Server $server */
        $server = Filament::getTenant();
        if ($state->status === InstalledOperationState::STATUS_COMPLETED) {
            app(InstalledOperationManager::class)->forget(
                $server,
                $state->projectType,
                $state->operation,
            );
        }
    }

    /** @param array<string, mixed>|null $previousOperation */
    protected function shouldSkipInstalledOperationPollRender(?array $previousOperation, InstalledOperationState $state): bool
    {
        // Scan progress is intentionally not shown outside Installed. While a
        // catalog is open, intermediate queued/running updates therefore do
        // not need to rebuild the catalog table or its image DOM. A terminal
        // state still renders below so the count badge and cache refresh are
        // applied immediately.
        if ($state->isActive()
            && $state->operation === InstalledOperationManager::OPERATION_SCAN
            && $this->activeTab !== 'installed') {
            return true;
        }

        return $state->isActive() && $this->installedOperation === $previousOperation;
    }

    /**
     * Invalidate the table records so a background enrichment fill (project
     * metadata or a latest-version lookup queued by SourceCache::swrDeferred())
     * that landed since the last render becomes visible. The poll request
     * itself re-renders the component; unlike resetTable(), this preserves
     * the Installed table's current page, search, and filters.
     */
    public function pollEnrichment(): void
    {
        if ($this->activeTab !== 'installed') {
            $this->pollEnrichment = false;

            return;
        }

        $this->flushCachedTableRecords();
    }

    protected function refreshInstalledOperationState(): ?InstalledOperationState
    {
        /** @var Server $server */
        $server = Filament::getTenant();
        $type = static::detectProjectType($server);

        if (!$type) {
            $this->installedOperation = null;
            $this->pollInstalledOperations = false;

            return null;
        }

        $operations = app(InstalledOperationManager::class);
        $scanState = $operations->state($server, $type, InstalledOperationManager::OPERATION_SCAN);

        // Scan and bulk update are mutually exclusive. The common active
        // scan path therefore needs only one cache lookup per two-second
        // poll instead of reading the unrelated bulk-update state as well.
        if ($scanState?->isActive()) {
            $this->setInstalledOperationState($scanState);

            return $scanState;
        }

        $states = array_values(array_filter([
            $scanState,
            $operations->state($server, $type, InstalledOperationManager::OPERATION_BULK_UPDATE),
        ]));

        usort($states, function (InstalledOperationState $left, InstalledOperationState $right): int {
            if ($left->isActive() !== $right->isActive()) {
                return $left->isActive() ? -1 : 1;
            }

            return strcmp(
                $right->finishedAt ?? $right->startedAt ?? $right->queuedAt,
                $left->finishedAt ?? $left->startedAt ?? $left->queuedAt,
            );
        });

        $state = $states[0] ?? null;
        $this->setInstalledOperationState($state);

        return $state;
    }

    /**
     * Scan progress is inline only while Installed is open. Bulk-update
     * progress intentionally keeps its existing page-level visibility.
     */
    protected function shouldShowInstalledOperationStatus(): bool
    {
        if (($this->installedOperation['operation'] ?? null) === InstalledOperationManager::OPERATION_BULK_UPDATE) {
            return true;
        }

        if ($this->activeTab !== 'installed') {
            return false;
        }

        $operation = $this->getInstalledOperationDisplayPayload();

        if (($operation['operation'] ?? null) !== InstalledOperationManager::OPERATION_SCAN) {
            return false;
        }

        $status = $operation['status'] ?? null;

        return in_array($status, [
            InstalledOperationState::STATUS_QUEUED,
            InstalledOperationState::STATUS_RUNNING,
        ], true)
            || ($status === InstalledOperationState::STATUS_COMPLETED
                && $this->isInstalledScanCompletionVisible());
    }

    /** @return array<string, mixed>|null */
    protected function getInstalledOperationDisplayPayload(): ?array
    {
        return $this->isInstalledScanCompletionVisible()
            ? $this->installedScanCompletion
            : $this->installedOperation;
    }

    protected function isInstalledScanCompletionVisible(): bool
    {
        $completion = $this->installedScanCompletion;
        $finishedAt = $completion['finished_at'] ?? null;

        if ($this->activeTab !== 'installed'
            || ($completion['operation'] ?? null) !== InstalledOperationManager::OPERATION_SCAN
            || ($completion['status'] ?? null) !== InstalledOperationState::STATUS_COMPLETED
            || !is_string($finishedAt)) {
            return false;
        }

        try {
            return Carbon::parse($finishedAt)
                ->addSeconds(self::INSTALLED_SCAN_COMPLETION_VISIBLE_SECONDS)
                ->isFuture();
        } catch (Exception) {
            return false;
        }
    }

    /** @return array<string, string> */
    protected function installedOperationStatusExtraAttributes(): array
    {
        if (!$this->isInstalledScanCompletionVisible()) {
            return [];
        }

        try {
            $finishedAt = Carbon::parse($this->installedScanCompletion['finished_at']);
        } catch (Exception) {
            return [];
        }

        // The deadline is absolute, not "now + five seconds". Livewire can
        // re-render during the outcome window, but that must not reset its
        // timer and make the completion badge stick around indefinitely.
        $deadline = $finishedAt
            ->addSeconds(self::INSTALLED_SCAN_COMPLETION_VISIBLE_SECONDS)
            ->getTimestamp() * 1000;

        return [
            'x-data' => '{}',
            'x-init' => 'const remaining = Math.max(0, '.$deadline.' - Date.now()); if (remaining === 0) { $el.remove(); } else { setTimeout(() => $el.remove(), remaining); }',
        ];
    }

    /**
     * Whether the status badge's loader icon should spin - true while
     * something is queued, running, or about to be dispatched, false for
     * the terminal completed/failed states and the static queue-config
     * warning (all of which mean nothing is actually in progress anymore).
     */
    protected function installedOperationIsActive(): bool
    {
        $status = $this->getInstalledOperationDisplayPayload()['status'] ?? null;

        if (in_array($status, [InstalledOperationState::STATUS_COMPLETED, InstalledOperationState::STATUS_FAILED], true)) {
            return false;
        }

        return !($status === null && $this->operationQueueWarningShown);
    }

    protected function shouldPollInstalledOperation(?InstalledOperationState $state): bool
    {
        if ($state === null) {
            // A valid scan result already covers this case, so there is
            // nothing to poll for. Without this, a caller like mount() that
            // already knows installedScanDataReady is true would still enable
            // polling here until the next request corrected it.
            return $this->activeTab === 'installed'
                && !$this->operationQueueWarningShown
                && !$this->installedScanDataReady;
        }

        if ($state->isActive()) {
            return true;
        }

        return $this->handledInstalledOperation !== $state->operation.':'.($state->finishedAt ?? '');
    }

    protected function installedOperationStatus(): string
    {
        $installedOperation = $this->getInstalledOperationDisplayPayload();

        if ($installedOperation === null) {
            return trans($this->operationQueueWarningShown
                ? 'pelican-minecraft-modrinth::strings.operations.queue_required'
                : 'pelican-minecraft-modrinth::strings.operations.checking');
        }

        $operation = trans(
            $installedOperation['operation'] === InstalledOperationManager::OPERATION_BULK_UPDATE
                ? 'pelican-minecraft-modrinth::strings.operations.bulk_update'
                : 'pelican-minecraft-modrinth::strings.operations.scan',
        );
        $status = $installedOperation['status'] ?? null;
        $progress = (int) ($installedOperation['progress'] ?? 0);
        $total = $installedOperation['total'] ?? null;

        return match ($status) {
            InstalledOperationState::STATUS_QUEUED => trans('pelican-minecraft-modrinth::strings.operations.queued', compact('operation')),
            InstalledOperationState::STATUS_RUNNING => is_int($total) && $total > 0
                ? trans('pelican-minecraft-modrinth::strings.operations.running_progress', compact('operation', 'progress', 'total'))
                : trans('pelican-minecraft-modrinth::strings.operations.running', compact('operation')),
            InstalledOperationState::STATUS_COMPLETED => trans('pelican-minecraft-modrinth::strings.operations.completed', compact('operation')),
            InstalledOperationState::STATUS_FAILED => trans('pelican-minecraft-modrinth::strings.operations.failed', compact('operation')),
            default => '',
        };
    }

    protected function notifyInstalledOperationFinished(InstalledOperationState $state): void
    {
        if ($state->status === InstalledOperationState::STATUS_FAILED) {
            Notification::make()
                ->title(trans('pelican-minecraft-modrinth::strings.operations.failed', [
                    'operation' => trans(
                        $state->operation === InstalledOperationManager::OPERATION_BULK_UPDATE
                            ? 'pelican-minecraft-modrinth::strings.operations.bulk_update'
                            : 'pelican-minecraft-modrinth::strings.operations.scan',
                    ),
                ]))
                ->danger()
                ->send();

            return;
        }

        if ($state->operation === InstalledOperationManager::OPERATION_BULK_UPDATE) {
            $updated = (int) ($state->result['updated'] ?? 0);
            $failed = (int) ($state->result['failed'] ?? 0);

            if ($updated === 0 && $failed === 0) {
                Notification::make()
                    ->title(trans('pelican-minecraft-modrinth::strings.notifications.bulk_update_none'))
                    ->info()
                    ->send();

                return;
            }

            $notification = Notification::make()
                ->title($failed > 0
                    ? trans('pelican-minecraft-modrinth::strings.notifications.bulk_update_partial', [
                        'updated' => $updated,
                        'failed' => $failed,
                    ])
                    : trans('pelican-minecraft-modrinth::strings.notifications.bulk_update_success', ['count' => $updated]));

            if ($failed > 0) {
                $notification->warning();
            } else {
                $notification->success();
            }

            $notification->send();

            return;
        }

        // Successful scans are deliberately represented by the short-lived
        // Installed-tab status instead of a global Filament notification.
    }

    /**
     * @param  array{dispatched: bool, reason: ?string, state: ?InstalledOperationState}  $dispatch
     */
    protected function notifyInstalledOperationDispatched(array $dispatch): void
    {
        $state = $dispatch['state'];
        if ($state !== null) {
            $this->setInstalledOperationState($state);
        }

        if ($dispatch['dispatched']) {
            if ($state !== null && $state->operation === InstalledOperationManager::OPERATION_SCAN) {
                // Scan progress belongs in the Installed tab, not in a
                // global notification. The tab condition is enforced by
                // shouldShowInstalledOperationStatus().
                return;
            }

            Notification::make()
                ->title(trans('pelican-minecraft-modrinth::strings.operations.dispatched'))
                ->info()
                ->send();

            return;
        }

        $reason = $dispatch['reason'];

        if ($reason === 'sync_queue') {
            $this->operationQueueWarningShown = true;
            $this->pollInstalledOperations = false;
        }

        // dispatchScan()/dispatchBulkUpdate() persist a terminal failed
        // state when their dispatcher throws. This method is already showing
        // the immediate dispatch error, so do not let the next two-second
        // poll show a second, generic operation-failed notification.
        if ($reason === 'dispatch_failed' && $state !== null) {
            $this->markInstalledOperationHandled($state);
        }

        $title = match ($reason) {
            'already_active' => trans('pelican-minecraft-modrinth::strings.operations.already_active'),
            'sync_queue' => trans('pelican-minecraft-modrinth::strings.operations.queue_required'),
            default => trans('pelican-minecraft-modrinth::strings.operations.dispatch_failed'),
        };

        $notification = Notification::make()
            ->title($title);

        if ($reason === 'already_active') {
            $notification->warning();
        } else {
            $notification->danger();
        }

        $notification->send();
    }
}
