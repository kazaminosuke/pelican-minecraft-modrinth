<?php

namespace Boy132\MinecraftModrinth;

use App\Contracts\Plugins\HasPluginSettings;
use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use App\Traits\EnvironmentWriterTrait;
use BladeUI\Icons\Factory as BladeIconsFactory;
use Boy132\MinecraftModrinth\Enums\ModrinthProjectType;
use Boy132\MinecraftModrinth\Filament\Server\Pages\MinecraftModrinthProjectPage;
use Boy132\MinecraftModrinth\Services\InstalledOperationManager;
use Boy132\MinecraftModrinth\Services\MinecraftModrinthService;
use Boy132\MinecraftModrinth\Support\CacheVersion;
use Exception;
use Filament\Actions\Action;
use Filament\Contracts\Plugin;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Panel;
use Filament\Schemas\Components\Actions;
use Filament\Tables\View\TablesRenderHook;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\HtmlString;

class MinecraftModrinthPlugin implements HasPluginSettings, Plugin
{
    use EnvironmentWriterTrait;

    public function getId(): string
    {
        return 'pelican-minecraft-modrinth';
    }

    public function register(Panel $panel): void
    {
        $id = str($panel->getId())->title();

        $panel->discoverPages(plugin_path($this->getId(), "src/Filament/$id/Pages"), "Boy132\\MinecraftModrinth\\Filament\\$id\\Pages");

        app(BladeIconsFactory::class)->add('mcloader', [
            'path' => plugin_path($this->getId(), 'resources/icons/loaders'),
            'prefix' => 'mcloader',
        ]);

        $panel->renderHook(
            TablesRenderHook::TOOLBAR_SEARCH_AFTER,
            fn () => new HtmlString(
                '<div class="mmr-catalog-sort-toolbar" x-cloak x-show="$wire.activeTab !== \'installed\'">'
                .'<label for="mmr-catalog-sort" class="fi-sr-only">'.e(trans('pelican-minecraft-modrinth::strings.table.sort.label')).'</label>'
                .'<select id="mmr-catalog-sort" wire:model.live="catalogSort" class="mmr-catalog-sort-select">'
                .'<option value="downloads">'.e(trans('pelican-minecraft-modrinth::strings.table.sort.downloads')).'</option>'
                .'<option value="updated">'.e(trans('pelican-minecraft-modrinth::strings.table.sort.updated')).'</option>'
                .'<option value="popularity">'.e(trans('pelican-minecraft-modrinth::strings.table.sort.popularity')).'</option>'
                .'</select></div>',
            ),
            MinecraftModrinthProjectPage::class,
        );
        $panel->renderHook(
            PanelsRenderHook::HEAD_END,
            fn () => new HtmlString(
                '<style>'
                .'.mcloader-badge .fi-icon{width:1em!important;height:1em!important;}'
                // The mod/plugin table owns the rest of the screen, and the row
                // area alone scrolls, so the Minecraft Version/Loader/Installed
                // summary and the source tabs above it never move and the
                // paginator below never moves either.
                //
                // Filament's own table markup (verified against its 4.x blade
                // view) is .fi-ta > .fi-ta-ctn > .fi-ta-main, with the row
                // viewport, the empty state and nav.fi-pagination as siblings
                // inside .fi-ta-main. Turning that into a fixed-height flex
                // column is what pins the paginator: the row viewport absorbs
                // all the slack, so neither the row count, nor how much the
                // descriptions wrap, nor the paginator briefly disappearing
                // during a deferred load (Filament renders it only once
                // $records is a paginator) can move anything.
                //
                // This replaces a JS pass that measured document.scrollHeight
                // and window.scrollY after every render. That made the reserved
                // height a function of how far the page happened to be scrolled
                // at the time, and each pass altered the input to the next -
                // the reason the paginator's position drifted by hundreds of
                // pixels and varied between tabs.
                //
                // What CSS cannot derive comes from the table-layout partial:
                // where the table starts, how much page chrome sits below it,
                // and the viewport height. All three are measured rather than
                // assumed - a fixed 1.5rem guess for the trailing chrome left
                // the document 64px taller than the viewport, which brought the
                // page's own scrollbar back. Subtracting the measured value
                // instead makes the document exactly one viewport tall, so the
                // row area is the only thing that scrolls. The fallbacks only
                // have to keep the first paint sane.
                .'.mmr-table-scroll-ctn .fi-ta-main{'
                    .'display:flex;flex-direction:column;'
                    .'height:calc('
                        .'var(--mmr-viewport-height, 100dvh)'
                        .' - var(--mmr-table-top, 24rem)'
                        .' - var(--mmr-table-bottom, 2rem)'
                    .');'
                    .'min-height:15rem;'
                .'}'
                // Header, toolbar, filter indicators and the paginator keep
                // their natural heights; only the row viewport flexes.
                .'.mmr-table-scroll-ctn .fi-ta-main>*{flex:0 0 auto;}'
                .'.mmr-table-scroll-ctn .fi-ta-content-ctn,'
                .'.mmr-table-scroll-ctn .fi-ta-empty-state{flex:1 1 auto;min-height:0;}'
                .'.mmr-table-scroll-ctn .fi-ta-content-ctn{overflow-y:auto;}'
                // Filament's paginator is a 1fr/auto/1fr grid. On this page
                // ->paginated([20]) renders no records-per-page selector, so
                // relying on auto-placement makes the count and page list trade
                // places as their contents change. Explicitly place the count in
                // the left equal track and the list in the central auto track.
                // Using minmax(0, 1fr), rather than a bare 1fr, prevents the
                // count's min-content width from making the two side tracks
                // unequal. The page list consequently stays centred regardless
                // of the count's length or whether it wraps.
                .'.mmr-table-scroll-ctn .fi-pagination{'
                    .'grid-template-columns:minmax(0,1fr) auto minmax(0,1fr);'
                .'}'
                .'.mmr-table-scroll-ctn .fi-pagination-overview{'
                    .'grid-column:1;min-inline-size:0;'
                    // Filament's text-sm line-height is 1.25rem. Reserving two
                    // lines from the first paint means a long overview can wrap
                    // without changing the paginator's block size or moving the
                    // row viewport above it.
                    .'min-block-size:2.5rem;white-space:normal;'
                    // Do not allow arbitrary character breaks. The overview
                    // formatter below supplies Japanese phrase boundaries where
                    // they are useful, while the browser's ordinary strict
                    // Japanese line-breaking rules handle every other locale.
                    .'overflow-wrap:normal;word-break:normal;line-break:strict;'
                .'}'
                .'.mmr-table-scroll-ctn .mmr-pagination-overview-content{min-inline-size:0;}'
                .'.mmr-table-scroll-ctn .mmr-pagination-overview-chunk{white-space:nowrap;}'
                // The overview is hidden in Filament's compact paginator. Only
                // switch it to a grid at the same wide breakpoints where
                // Filament shows it, so a single line can sit vertically centred
                // within the reserved two-line box without changing compact
                // pagination's native visibility rules.
                .'@supports (container-type:inline-size){'
                    .'@container (min-width:56rem){'
                        .'.mmr-table-scroll-ctn .fi-pagination-overview{display:grid;align-content:center;}'
                    .'}'
                .'}'
                .'@supports not (container-type:inline-size){'
                    .'@media (min-width:48rem){'
                        .'.mmr-table-scroll-ctn .fi-pagination-overview{display:grid;align-content:center;}'
                    .'}'
                .'}'
                .'.mmr-table-scroll-ctn .fi-pagination-items{grid-column:2;justify-self:center;}'
                // Filament conditionally omits the first/previous and
                // next/last <li>s. The table-layout script supplies a complete
                // native disabled item for a missing edge, avoiding a computed
                // spacer while making both navigation directions explicit.
                .'.mmr-table-scroll-ctn .mmr-pagination-placeholder{pointer-events:none;}'
                // The placeholder clones the available opposite-direction
                // icon, so only its glyph is mirrored; Filament's button size,
                // border, corner and disabled colours stay untouched.
                .'.mmr-table-scroll-ctn .mmr-pagination-placeholder-icon{transform:scaleX(-1);}'
                .'.mmr-catalog-sort-toolbar{width:12rem;min-width:10rem;}'
                .'.mmr-catalog-sort-select{display:block;width:100%;min-height:2.25rem;border-radius:.5rem;border:1px solid rgb(209 213 219);background:#fff;padding:.5rem .75rem;color:rgb(17 24 39);font-size:.875rem;line-height:1.25rem;}'
                .'.dark .mmr-catalog-sort-select{border-color:rgb(75 85 99);background:rgb(31 41 55);color:#fff;}'
                // Keeps the column header row (Title/Author/Downloads/Modified)
                // pinned to the top of that scrolling area as rows scroll past
                // underneath it; its own background (set by Filament) keeps rows
                // from showing through.
                .'.mmr-table-scroll-ctn .fi-ta-table>thead{position:sticky;top:0;z-index:1;}'
                // TextEntry exposes no extraImgAttributes()-style hook for
                // its icon, so this class goes on the entry's own wrapper
                // (via ->extraAttributes()) and reaches the icon - rendered
                // by Filament's generate_icon_html() as an inline <svg
                // class="fi-icon ...">, same as the .mcloader-badge rule
                // above - through a descendant selector instead. Matches
                // Filament's own .fi-loading-indicator (motion-safe:animate-spin)
                // in respecting a reduced-motion preference.
                .'@keyframes mmr-spin{to{transform:rotate(360deg);}}'
                .'@media (prefers-reduced-motion: no-preference){.mmr-installed-operation-spinning .fi-icon{animation:mmr-spin 1s linear infinite;}}'
                .'</style>'
            ),
        );
        // Supplies --mmr-table-top for the flex layout above, and puts the
        // paginator's inline offset back after a morph strips it.
        $panel->renderHook(
            PanelsRenderHook::BODY_END,
            fn () => view('pelican-minecraft-modrinth::components.table-layout'),
            MinecraftModrinthProjectPage::class,
        );
        $panel->renderHook(
            PanelsRenderHook::BODY_END,
            fn () => view('pelican-minecraft-modrinth::components.table-swr-cache'),
            MinecraftModrinthProjectPage::class,
        );

    }

    public function boot(Panel $panel): void {}

    /**
     * Return the current values for Filament's plugin settings slide-over.
     *
     * @return array<string, mixed>
     */
    public function getSettingsFormData(): array
    {
        return [
            'latest_minecraft_version' => config('pelican-minecraft-modrinth.latest_minecraft_version', '26.1.2'),
            'nav_sort' => env('MINECRAFT_MODRINTH_NAV_SORT', 11),
            'curseforge_api_key' => config('pelican-minecraft-modrinth.curseforge_api_key'),
            'github_token' => config('pelican-minecraft-modrinth.github_token'),
        ];
    }

    public function getSettingsForm(): array
    {
        return [
            TextInput::make('latest_minecraft_version')
                ->label(trans('pelican-minecraft-modrinth::strings.settings.latest_minecraft_version'))
                ->required()
                ->default(fn () => config('pelican-minecraft-modrinth.latest_minecraft_version', '26.1.2')),
            TextInput::make('nav_sort')
                ->label(trans('pelican-minecraft-modrinth::strings.settings.nav_sort'))
                ->helperText(trans('pelican-minecraft-modrinth::strings.settings.nav_sort_helper'))
                ->numeric()
                ->default(env('MINECRAFT_MODRINTH_NAV_SORT', 11)),
            TextInput::make('curseforge_api_key')
                ->label(trans('pelican-minecraft-modrinth::strings.settings.curseforge_api_key'))
                ->helperText(trans('pelican-minecraft-modrinth::strings.settings.curseforge_api_key_helper'))
                ->password()
                ->revealable()
                ->default(fn () => config('pelican-minecraft-modrinth.curseforge_api_key')),
            TextInput::make('github_token')
                ->label(trans('pelican-minecraft-modrinth::strings.settings.github_token'))
                ->helperText(trans('pelican-minecraft-modrinth::strings.settings.github_token_helper'))
                ->password()
                ->revealable()
                ->default(fn () => config('pelican-minecraft-modrinth.github_token')),
            // A standalone action embedded in the settings form's schema
            // (rather than a plugin-settings form field) - it runs
            // independently of the "Save" submission that PluginResource
            // wires up around this whole schema, so clicking it doesn't
            // require or trigger a settings save.
            Actions::make([
                Action::make('clear_cache')
                    ->label(trans('pelican-minecraft-modrinth::strings.settings.clear_cache'))
                    ->color('danger')
                    ->icon('tabler-trash')
                    ->modalHeading(trans('pelican-minecraft-modrinth::strings.settings.clear_cache_confirmation_heading'))
                    ->modalDescription(trans('pelican-minecraft-modrinth::strings.settings.clear_cache_confirmation_description'))
                    // A schema already makes this action open a confirmation
                    // modal (with the heading/description above) before
                    // running, the same as requiresConfirmation() would - so
                    // that isn't also needed here, which would risk stacking
                    // a second, redundant confirmation step in front of it.
                    ->schema([
                        Select::make('server_id')
                            ->label(trans('pelican-minecraft-modrinth::strings.settings.clear_cache_server_label'))
                            ->native(false)
                            ->required()
                            ->default('all')
                            // Array union (+), NOT spread (...) - spread
                            // renumbers integer keys sequentially (0, 1, 2...)
                            // instead of preserving them, which would silently
                            // replace every server's real id with an unrelated
                            // sequential number as this Select's option value.
                            ->options(fn () => ['all' => trans('pelican-minecraft-modrinth::strings.settings.clear_cache_all_servers')]
                                + Server::query()->orderBy('name')->pluck('name', 'id')->all()),
                    ])
                    ->action(function (array $data) {
                        $service = app(MinecraftModrinthService::class);
                        $operations = app(InstalledOperationManager::class);
                        /** @var DaemonFileRepository $fileRepository */
                        $fileRepository = app(DaemonFileRepository::class);

                        if (($data['server_id'] ?? 'all') === 'all') {
                            self::clearAllServers($service, $fileRepository);

                            return;
                        }

                        self::clearSingleServer($service, $fileRepository, $operations, (int) $data['server_id']);
                    }),
            ])->belowContent(trans('pelican-minecraft-modrinth::strings.settings.clear_cache_helper')),
        ];
    }

    public function saveSettings(array $data): void
    {
        $this->writeToEnvironment([
            'LATEST_MINECRAFT_VERSION' => $data['latest_minecraft_version'],
            'MINECRAFT_MODRINTH_NAV_SORT' => $data['nav_sort'],
            'CURSEFORGE_API_KEY' => $data['curseforge_api_key'] ?? '',
            'GITHUB_TOKEN' => $data['github_token'] ?? '',
        ]);

        Notification::make()
            ->title(trans('pelican-minecraft-modrinth::strings.settings.settings_saved'))
            ->success()
            ->send();
    }

    /**
     * Clears every server's installed-mods metadata/caches, plus the two
     * caches that aren't per-server at all (hydration display data has no
     * single global scope, but the Hangar hash-match cache does - see
     * CacheVersion). Deliberately does NOT re-scan every server
     * synchronously from this one request - doing that for every server,
     * each potentially hundreds of mods, in a single web request risks a
     * real timeout. Re-scanning instead happens lazily, the normal way, the
     * next time each server's Installed tab is loaded.
     */
    private static function clearAllServers(MinecraftModrinthService $service, DaemonFileRepository $fileRepository): void
    {
        $serverCount = CacheVersion::bumpAllHydration();
        CacheVersion::bumpHangarHash();

        // Metadata deletion needs each server's egg loaded (to resolve its
        // project type), unlike the cheap id-only query bumpAllHydration()
        // above does - a second query here is fine given how infrequently
        // this action runs.
        foreach (Server::query()->with('egg')->get() as $server) {
            try {
                $type = ModrinthProjectType::fromServer($server);

                if ($type) {
                    $service->clearInstalledModsMetadata($server, $fileRepository, $type);
                }

                if (ModrinthProjectType::supportsDatapacks($server)) {
                    $service->clearInstalledModsMetadata($server, $fileRepository, ModrinthProjectType::Datapack);
                }
            } catch (Exception $exception) {
                report($exception);
            }
        }

        Notification::make()
            ->title(trans('pelican-minecraft-modrinth::strings.settings.cache_cleared', ['count' => $serverCount]))
            ->success()
            ->send();
    }

    /**
     * Clears one server and queues a fresh scan without blocking the settings
     * request. Deliberately
     * does not bump the Hangar hash-match cache (see CacheVersion) since
     * that cache isn't per-server - bumping it here would affect every
     * other server too, contradicting "just this one server".
     */
    private static function clearSingleServer(
        MinecraftModrinthService $service,
        DaemonFileRepository $fileRepository,
        InstalledOperationManager $operations,
        int $serverId,
    ): void
    {
        if (!$operations->supportsAsyncDispatch()) {
            Notification::make()
                ->title(trans('pelican-minecraft-modrinth::strings.operations.queue_required'))
                ->danger()
                ->send();

            return;
        }

        $server = Server::query()->with('egg')->find($serverId);

        if (!$server) {
            Notification::make()
                ->title(trans('pelican-minecraft-modrinth::strings.notifications.reset_metadata_failed'))
                ->danger()
                ->send();

            return;
        }

        try {
            $type = ModrinthProjectType::fromServer($server);

            if ($type) {
                $scanState = $operations->state($server, $type, InstalledOperationManager::OPERATION_SCAN);
                if (!$scanState?->isActive()) {
                    $service->clearInstalledModsMetadata($server, $fileRepository, $type);
                    $dispatch = $operations->dispatchScan($server, $type, force: true);
                    if (!$dispatch['dispatched'] && $dispatch['reason'] !== 'already_active') {
                        throw new Exception('Failed to dispatch installed scan.');
                    }
                }
            }

            if (ModrinthProjectType::supportsDatapacks($server)) {
                $datapackState = $operations->state($server, ModrinthProjectType::Datapack, InstalledOperationManager::OPERATION_SCAN);
                if (!$datapackState?->isActive()) {
                    $service->clearInstalledModsMetadata($server, $fileRepository, ModrinthProjectType::Datapack);
                    $dispatch = $operations->dispatchScan($server, ModrinthProjectType::Datapack, force: true);
                    if (!$dispatch['dispatched'] && $dispatch['reason'] !== 'already_active') {
                        throw new Exception('Failed to dispatch datapack scan.');
                    }
                }
            }
        } catch (Exception $exception) {
            report($exception);

            Notification::make()
                ->title(trans('pelican-minecraft-modrinth::strings.notifications.reset_metadata_failed'))
                ->danger()
                ->send();

            return;
        }

        Notification::make()
            ->title(trans('pelican-minecraft-modrinth::strings.settings.cache_cleared_single', ['name' => $server->name]))
            ->success()
            ->send();
    }
}
