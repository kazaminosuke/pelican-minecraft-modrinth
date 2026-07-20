<?php

namespace Boy132\MinecraftModrinth\Filament\Server\Pages;

use App\Models\Server;
use Boy132\MinecraftModrinth\Enums\ModrinthProjectType;

class MinecraftDatapackPage extends MinecraftModrinthProjectPage
{
    protected static string|\BackedEnum|null $navigationIcon = 'tabler-file-zip';

    protected static ?string $slug = 'mod-manager-datapacks';

    public static function getNavigationSort(): ?int
    {
        return parent::getNavigationSort() + 1;
    }

    public static function getNavigationLabel(): string
    {
        return trans('pelican-minecraft-modrinth::strings.minecraft_datapacks');
    }

    protected static function detectProjectType(Server $server): ?ModrinthProjectType
    {
        if (!ModrinthProjectType::supportsDatapacks($server)) {
            return null;
        }

        return ModrinthProjectType::Datapack;
    }
}
