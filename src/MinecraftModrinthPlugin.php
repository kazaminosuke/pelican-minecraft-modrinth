<?php

namespace Boy132\MinecraftModrinth;

use App\Contracts\Plugins\HasPluginSettings;
use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use App\Traits\EnvironmentWriterTrait;
use BladeUI\Icons\Factory as BladeIconsFactory;
use Boy132\MinecraftModrinth\Enums\ModrinthProjectType;
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
            PanelsRenderHook::HEAD_END,
            fn () => new HtmlString(
                '<style>'
                .'.mcloader-badge .fi-icon{width:1em!important;height:1em!important;}'
                // Scopes an internal scrollbar to just the mod/plugin table's row
                // area (.fi-ta-content-ctn - confirmed via Filament's own table
                // blade view to sit below the toolbar/search bar and above the
                // pagination controls, both of which stay outside this rule's
                // reach) so the Minecraft Version/Loader/Installed summary and
                // source tabs above it never move, on long lists (e.g. large
                // modpacks) and when switching between source tabs. max-height is
                // NOT set here: a CSS calc() estimate of the space above the
                // table proved too fragile (it left the page itself scrollable in
                // addition to the table) since that space depends on the topbar,
                // sidebar mode, and this page's own header wrapping - it's set
                // dynamically instead, in pixels (or left uncapped when the
                // content already fits), by MinecraftModrinthProjectPage's
                // queueTableHeightRecalculation(), which measures actual layout
                // overflow after each render. min-height here is only a floor
                // for the moment before that script has run for the first time
                // (avoids a flash of a collapsed table pre-measurement) - that
                // script overrides it with an inline min-height: 0 on every
                // run after, so a genuinely short result set isn't padded out
                // to this floor.
                .'.mmr-table-scroll-ctn .fi-ta-content-ctn{min-height:15rem;overflow-y:auto;}'
                // Keeps the column header row (Title/Author/Downloads/Modified)
                // pinned to the top of that scrolling area as rows scroll past
                // underneath it; its own background (set by Filament) keeps rows
                // from showing through.
                .'.mmr-table-scroll-ctn .fi-ta-table>thead{position:sticky;top:0;z-index:1;}'
                .'</style>'
            ),
        );
    }

    public function boot(Panel $panel): void {}

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
                        /** @var DaemonFileRepository $fileRepository */
                        $fileRepository = app(DaemonFileRepository::class);

                        if (($data['server_id'] ?? 'all') === 'all') {
                            self::clearAllServers($service, $fileRepository);

                            return;
                        }

                        self::clearSingleServer($service, $fileRepository, (int) $data['server_id']);
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
     * Clears and immediately re-scans a single server - unlike
     * clearAllServers(), this can afford a synchronous re-scan since it's
     * scoped to one server's mods rather than every server's. Deliberately
     * does not bump the Hangar hash-match cache (see CacheVersion) since
     * that cache isn't per-server - bumping it here would affect every
     * other server too, contradicting "just this one server".
     */
    private static function clearSingleServer(MinecraftModrinthService $service, DaemonFileRepository $fileRepository, int $serverId): void
    {
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
                $service->resetInstalledMods($server, $fileRepository, $type);
            }

            if (ModrinthProjectType::supportsDatapacks($server)) {
                $service->resetInstalledMods($server, $fileRepository, ModrinthProjectType::Datapack);
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
