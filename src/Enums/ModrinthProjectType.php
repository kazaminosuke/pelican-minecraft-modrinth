<?php

namespace Boy132\MinecraftModrinth\Enums;

use App\Models\Server;
use Filament\Support\Contracts\HasLabel;

enum ModrinthProjectType: string implements HasLabel
{
    case Mod = 'mod';
    case Plugin = 'plugin';
    case Datapack = 'datapack';

    public function getLabel(): string
    {
        return match ($this) {
            self::Mod => trans('pelican-minecraft-modrinth::strings.minecraft_mods'),
            self::Plugin => trans('pelican-minecraft-modrinth::strings.minecraft_plugins'),
            self::Datapack => trans('pelican-minecraft-modrinth::strings.minecraft_datapacks'),
        };
    }

    public function getFolder(?Server $server = null): string
    {
        return match ($this) {
            self::Mod => 'mods',
            self::Plugin => 'plugins',
            self::Datapack => 'world/datapacks',
        };
    }

    public function getFileExtension(): string
    {
        return match ($this) {
            self::Mod, self::Plugin => '.jar',
            self::Datapack => '.zip',
        };
    }

    public function getModrinthLoader(Server $server): ?string
    {
        return match ($this) {
            self::Datapack => 'datapack',
            default => MinecraftLoader::fromServer($server)?->value,
        };
    }

    public static function fromServer(Server $server): ?ModrinthProjectType
    {
        $server->loadMissing('egg');

        $features = $server->egg->features ?? [];
        $tags = $server->egg->tags ?? [];

        if (in_array('modrinth_plugins', $features) || (in_array('minecraft', $tags) && in_array('plugins', $features))) {
            return self::Plugin;
        }

        if (in_array('modrinth_mods', $features) || (in_array('minecraft', $tags) && in_array('mods', $features))) {
            return self::Mod;
        }

        return null;
    }

    public static function supportsDatapacks(Server $server): bool
    {
        $server->loadMissing('egg');

        $features = $server->egg->features ?? [];

        return in_array('modrinth_datapacks', $features);
    }
}
