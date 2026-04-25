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
                ->default(fn () => config('pelican-minecraft-modrinth.latest_minecraft_version', '1.21.11')),
        ];
    }

    public function saveSettings(array $data): void
    {
        $this->writeToEnvironment([
            'LATEST_MINECRAFT_VERSION' => $data['latest_minecraft_version'],
        ]);

        Notification::make()
            ->title(trans('pelican-minecraft-modrinth::strings.settings.settings_saved'))
            ->success()
            ->send();
    }
}
