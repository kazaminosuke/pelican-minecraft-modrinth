<?php

namespace Kazaminosuke\ModManager\Providers;

use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;
use Kazaminosuke\ModManager\Contracts\SourceFetchExecutorInterface;
use Kazaminosuke\ModManager\Services\InstalledOperationManager;
use Kazaminosuke\ModManager\Services\InstalledProjectService;
use Kazaminosuke\ModManager\Services\VersionLookupCoordinator;
use Kazaminosuke\ModManager\Sources\CurseForgeSource;
use Kazaminosuke\ModManager\Sources\GitHubReleasesSource;
use Kazaminosuke\ModManager\Sources\HangarSource;
use Kazaminosuke\ModManager\Sources\ModrinthSource;
use Kazaminosuke\ModManager\Support\MinecraftVersionResolver;
use Kazaminosuke\ModManager\Support\ProjectSourceRegistry;
use Kazaminosuke\ModManager\Support\SourceCache;
use Kazaminosuke\ModManager\Support\SourceFetchExecutor;

class ModManagerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SourceFetchExecutorInterface::class, SourceFetchExecutor::class);

        foreach ([
            SourceCache::class,
            ModrinthSource::class,
            CurseForgeSource::class,
            HangarSource::class,
            GitHubReleasesSource::class,
            ProjectSourceRegistry::class,
            VersionLookupCoordinator::class,
            InstalledProjectService::class,
            InstalledOperationManager::class,
        ] as $service) {
            $this->app->singleton($service);
        }
    }

    public function boot(): void
    {
        Queue::looping(function (): void {
            MinecraftVersionResolver::clear();

            if ($this->app->resolved(InstalledProjectService::class)) {
                $this->app->make(InstalledProjectService::class)->clearRuntimeCaches();
            }
        });
    }
}
