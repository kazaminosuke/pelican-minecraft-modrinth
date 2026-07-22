<?php

namespace Boy132\MinecraftModrinth;

use App\Contracts\Plugins\HasPluginSettings;
use App\Traits\EnvironmentWriterTrait;
use BladeUI\Icons\Factory as BladeIconsFactory;
use Filament\Contracts\Plugin;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Panel;
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
                // dynamically instead, in pixels, by the x-init script on this
                // element's wrapper (see MinecraftModrinthProjectPage::content()),
                // which measures the actual remaining viewport space. min-height
                // is a floor for the moment before that script runs.
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
}
