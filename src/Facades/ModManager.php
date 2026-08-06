<?php

namespace Kazaminosuke\ModManager\Facades;

use App\Models\Server;
use Illuminate\Support\Facades\Facade;
use Kazaminosuke\ModManager\Enums\ProjectType;
use Kazaminosuke\ModManager\Services\InstalledProjectService;

/**
 * @method static ?string getMinecraftVersion(Server $server)
 * @method static ?string getMinecraftLoader(Server $server)
 * @method static ?ProjectType getProjectType(Server $server)
 * @method static string getHashScanCacheKey(Server $server, ?ProjectType $type = null)
 * @method static string getProjectFolder(Server $server, \App\Repositories\Daemon\DaemonFileRepository $fileRepository, ?ProjectType $type = null)
 * @method static string getDatapackWorldName(Server $server, \App\Repositories\Daemon\DaemonFileRepository $fileRepository)
 * @method static void clearInstalledModsMetadata(Server $server, \App\Repositories\Daemon\DaemonFileRepository $fileRepository, ?ProjectType $type = null)
 * @method static array<string> resetInstalledMods(Server $server, \App\Repositories\Daemon\DaemonFileRepository $fileRepository, ?ProjectType $type = null)
 *
 * @see InstalledProjectService
 */
class ModManager extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return InstalledProjectService::class;
    }
}
