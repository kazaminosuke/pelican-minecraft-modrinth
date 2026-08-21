<?php

namespace Kazaminosuke\ModManager\Filament\Admin;

use App\Models\Server;
use Filament\Actions\Action;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Kazaminosuke\ModManager\ModManagerPlugin;
use Kazaminosuke\ModManager\Repositories\ServerModManagerSettingRepository;
use Kazaminosuke\ModManager\Support\EggProfileResolver;
use Kazaminosuke\ModManager\Support\ServerModManagerSettings;

/**
 * The root-admin-only tab added to Admin > Server > Edit > Mod Manager.
 *
 * These fields are intentionally not Server attributes. Every setting field
 * is dehydrated(false), and the explicit action reads its live state and
 * writes the separate settings row after a fresh root-admin check.
 */
final class ServerModManagerTab
{
    private const ENABLED = 'mod_manager_enabled';

    /** @var array<string, string> */
    private const PERMISSION_FIELDS = [
        'allow_user_egg_profile_edit' => 'mod_manager_allow_user_egg_profile_edit',
        'allow_user_project_install' => 'mod_manager_allow_user_project_install',
        'allow_user_project_update' => 'mod_manager_allow_user_project_update',
        'allow_user_project_delete' => 'mod_manager_allow_user_project_delete',
    ];

    public static function make(): Tab
    {
        return Tab::make('mod_manager')
            ->label(trans('pelican-minecraft-modrinth::strings.server_mod_manager.tab'))
            ->icon('tabler-packages')
            ->visible(fn (): bool => self::isRootAdmin())
            ->schema([
                Section::make(trans('pelican-minecraft-modrinth::strings.server_mod_manager.access'))
                    ->description(trans('pelican-minecraft-modrinth::strings.server_mod_manager.access_helper'))
                    ->columns(2)
                    ->schema([
                        Toggle::make(self::ENABLED)
                            ->label(trans('pelican-minecraft-modrinth::strings.server_mod_manager.enabled'))
                            ->helperText(trans('pelican-minecraft-modrinth::strings.server_mod_manager.enabled_helper'))
                            ->formatStateUsing(fn (Server $record): bool => app(ServerModManagerSettings::class)->isEnabled($record))
                            ->dehydrated(false),
                        ToggleButtons::make(self::PERMISSION_FIELDS['allow_user_egg_profile_edit'])
                            ->label(trans('pelican-minecraft-modrinth::strings.settings.allow_user_egg_profile_edit'))
                            ->options(self::permissionOptions('allow_user_egg_profile_edit'))
                            ->formatStateUsing(fn (Server $record): string => self::overrideState($record, 'allow_user_egg_profile_edit'))
                            ->inline()
                            ->dehydrated(false)
                            ->columnSpanFull(),
                        ToggleButtons::make(self::PERMISSION_FIELDS['allow_user_project_install'])
                            ->label(trans('pelican-minecraft-modrinth::strings.settings.allow_user_project_install'))
                            ->options(self::permissionOptions('allow_user_project_install'))
                            ->formatStateUsing(fn (Server $record): string => self::overrideState($record, 'allow_user_project_install'))
                            ->inline()
                            ->dehydrated(false)
                            ->columnSpanFull(),
                        ToggleButtons::make(self::PERMISSION_FIELDS['allow_user_project_update'])
                            ->label(trans('pelican-minecraft-modrinth::strings.settings.allow_user_project_update'))
                            ->options(self::permissionOptions('allow_user_project_update'))
                            ->formatStateUsing(fn (Server $record): string => self::overrideState($record, 'allow_user_project_update'))
                            ->inline()
                            ->dehydrated(false)
                            ->columnSpanFull(),
                        ToggleButtons::make(self::PERMISSION_FIELDS['allow_user_project_delete'])
                            ->label(trans('pelican-minecraft-modrinth::strings.settings.allow_user_project_delete'))
                            ->options(self::permissionOptions('allow_user_project_delete'))
                            ->formatStateUsing(fn (Server $record): string => self::overrideState($record, 'allow_user_project_delete'))
                            ->inline()
                            ->dehydrated(false)
                            ->columnSpanFull(),
                    ]),
                Section::make(trans('pelican-minecraft-modrinth::strings.server_mod_manager.egg_profile'))
                    ->description(trans('pelican-minecraft-modrinth::strings.server_mod_manager.egg_profile_helper'))
                    ->schema([
                        Actions::make([
                            Action::make('edit_egg_profile')
                                ->label(trans('pelican-minecraft-modrinth::strings.server_mod_manager.edit_egg_profile'))
                                ->icon('tabler-egg')
                                ->schema(ModManagerPlugin::eggProfileFormSchema(includeEggSelect: false))
                                ->fillForm(function (Server $record): array {
                                    $record->loadMissing('egg');

                                    return ModManagerPlugin::eggProfileDefaults($record->egg);
                                })
                                ->action(function (Server $record, array $data): void {
                                    self::authorizeRootAdmin();
                                    $record->loadMissing('egg');

                                    if ($record->egg === null) {
                                        return;
                                    }

                                    $data['egg_id'] = $record->egg->getKey();
                                    ModManagerPlugin::saveEggProfile($data);
                                    EggProfileResolver::clear();
                                }),
                        ]),
                    ]),
                Actions::make([
                    Action::make('save_mod_manager_settings')
                        ->label(trans('pelican-minecraft-modrinth::strings.server_mod_manager.save'))
                        ->icon('tabler-device-floppy')
                        ->color('primary')
                        ->authorize(fn (): bool => self::isRootAdmin())
                        ->action(function (Server $record, Get $get): void {
                            self::save($record, self::stateFrom($get));
                        }),
                ]),
            ]);
    }

    /**
     * Save the non-Server state. This method is deliberately public so the
     * Action and focused unit tests share the exact same authorization path.
     *
     * @param array<string, mixed> $data
     */
    public static function save(Server $server, array $data): void
    {
        self::authorizeRootAdmin();

        $repository = app(ServerModManagerSettingRepository::class);
        $current = $repository->forServer($server);
        $attributes = [
            'enabled' => array_key_exists(self::ENABLED, $data)
                ? (bool) $data[self::ENABLED]
                : ($current?->enabled ?? true),
        ];

        foreach (self::PERMISSION_FIELDS as $globalKey => $field) {
            $attributes[$globalKey] = array_key_exists($field, $data)
                ? self::decodeOverride($data[$field])
                : $current?->{$globalKey};
        }

        $repository->save($server, $attributes);

        Notification::make()
            ->title(trans('pelican-minecraft-modrinth::strings.server_mod_manager.saved'))
            ->success()
            ->send();
    }

    /**
     * @return array<string, string>
     */
    public static function permissionOptions(string $globalKey): array
    {
        $global = app(ServerModManagerSettings::class)->global($globalKey);
        $globalState = $global
            ? trans('pelican-minecraft-modrinth::strings.server_mod_manager.on')
            : trans('pelican-minecraft-modrinth::strings.server_mod_manager.off');

        return [
            'inherit' => trans('pelican-minecraft-modrinth::strings.server_mod_manager.inherit', ['state' => $globalState]),
            'allow' => trans('pelican-minecraft-modrinth::strings.server_mod_manager.allow'),
            'deny' => trans('pelican-minecraft-modrinth::strings.server_mod_manager.deny'),
        ];
    }

    private static function overrideState(Server $server, string $globalKey): string
    {
        return match (app(ServerModManagerSettings::class)->override($server, $globalKey)) {
            true => 'allow',
            false => 'deny',
            default => 'inherit',
        };
    }

    /** @return array<string, mixed> */
    private static function stateFrom(Get $get): array
    {
        return [
            self::ENABLED => $get(self::ENABLED),
            ...array_combine(
                array_values(self::PERMISSION_FIELDS),
                array_map(static fn (string $field): mixed => $get($field), array_values(self::PERMISSION_FIELDS)),
            ),
        ];
    }

    private static function decodeOverride(mixed $value): ?bool
    {
        return match ($value) {
            true, 1, '1', 'allow' => true,
            false, 0, '0', 'deny' => false,
            default => null,
        };
    }

    private static function isRootAdmin(): bool
    {
        return (bool) user()?->isRootAdmin();
    }

    private static function authorizeRootAdmin(): void
    {
        abort_unless(self::isRootAdmin(), 403);
    }
}
