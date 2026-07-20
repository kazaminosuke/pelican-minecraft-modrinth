<?php

namespace Boy132\MinecraftModrinth\Filament\Server\Pages;

use App\Models\Server;
use Boy132\MinecraftModrinth\Enums\ModrinthProjectType;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Schemas\Schema;

/**
 * Kept at the plugin's original "modrinth-datapacks" URL slug so links or
 * bookmarks made before the multi-source rename keep working, redirecting
 * straight to MinecraftDatapackPage (now at "mod-manager-datapacks"). Hidden
 * from navigation - only reachable by visiting the old URL directly.
 */
class MinecraftDatapackLegacyRedirectPage extends Page
{
    protected static ?string $slug = 'modrinth-datapacks';

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function canAccess(): bool
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        return parent::canAccess() && ModrinthProjectType::supportsDatapacks($server);
    }

    public function mount(): void
    {
        $this->redirect(MinecraftDatapackPage::getUrl());
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([]);
    }
}
