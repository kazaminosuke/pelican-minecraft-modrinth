<?php

namespace Boy132\MinecraftModrinth\Filament\Server\Pages;

use App\Models\Server;
use Boy132\MinecraftModrinth\Enums\ModrinthProjectType;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Schemas\Schema;

/**
 * Kept at the plugin's original "modrinth" URL slug so links or bookmarks made
 * before the multi-source rename keep working, redirecting straight to
 * MinecraftModrinthProjectPage (now at "mod-manager"). Hidden from
 * navigation - only reachable by visiting the old URL directly.
 */
class MinecraftModrinthLegacyRedirectPage extends Page
{
    protected static ?string $slug = 'modrinth';

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function canAccess(): bool
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        return parent::canAccess() && ModrinthProjectType::fromServer($server) !== null;
    }

    public function mount(): void
    {
        $this->redirect(MinecraftModrinthProjectPage::getUrl());
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([]);
    }
}
