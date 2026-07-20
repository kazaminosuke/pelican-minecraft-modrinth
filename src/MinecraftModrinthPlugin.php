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
            fn () => new HtmlString('<style>.mcloader-badge .fi-icon{width:1em!important;height:1em!important;}</style>'),
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
