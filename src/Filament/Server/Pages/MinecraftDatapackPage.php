<?php

namespace Kazaminosuke\ModManager\Filament\Server\Pages;

use App\Models\Server;
use Kazaminosuke\ModManager\Enums\ProjectType;

class MinecraftDatapackPage extends ModManagerPage
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

    protected static function detectProjectType(Server $server): ?ProjectType
    {
        if (!ProjectType::supportsDatapacks($server)) {
            return null;
        }

        return ProjectType::Datapack;
    }
}
