<?php

namespace Kazaminosuke\ModManager\Support;

use App\Models\Server;
use Kazaminosuke\ModManager\Enums\ProjectOperation;
use Kazaminosuke\ModManager\Models\ModManagerServerSetting;
use Kazaminosuke\ModManager\Repositories\ServerModManagerSettingRepository;

/**
 * Resolves global and optional server-specific Mod Manager settings.
 *
 * This class contains no per-server state. Request-local memoization belongs
 * to ServerModManagerSettingRepository, while this resolver only applies the
 * nullable-override rule (null inherits, false remains false).
 */
final class ServerModManagerSettings
{
    public function __construct(
        private readonly ServerModManagerSettingRepository $repository,
    ) {}

    public function isEnabled(Server|int $server): bool
    {
        return $this->repository->forServer($server)?->enabled ?? true;
    }

    public function allowsEggProfileEdit(Server|int $server): bool
    {
        return $this->resolve($server, 'allow_user_egg_profile_edit');
    }

    public function allowsProjectOperation(Server|int $server, ProjectOperation $operation): bool
    {
        return $this->resolve($server, $operation->allowsUserConfigKey());
    }

    /**
     * Resolve one global permission key through its server override.
     *
     * @param string $globalConfigKey A key under pelican-minecraft-modrinth.
     */
    public function resolve(Server|int $server, string $globalConfigKey): bool
    {
        $override = $this->override($server, $globalConfigKey);

        return $override ?? $this->global($globalConfigKey);
    }

    public function global(string $globalConfigKey): bool
    {
        return (bool) config('pelican-minecraft-modrinth.'.$globalConfigKey, false);
    }

    public function override(Server|int $server, string $globalConfigKey): ?bool
    {
        $setting = $this->repository->forServer($server);

        if (!$setting instanceof ModManagerServerSetting) {
            return null;
        }

        $field = match ($globalConfigKey) {
            'allow_user_egg_profile_edit' => 'allow_user_egg_profile_edit',
            'allow_user_project_install' => 'allow_user_project_install',
            'allow_user_project_update' => 'allow_user_project_update',
            'allow_user_project_delete' => 'allow_user_project_delete',
            default => throw new \InvalidArgumentException("Unknown Mod Manager setting: {$globalConfigKey}"),
        };

        return $setting->{$field};
    }
}
