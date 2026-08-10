<?php

namespace Kazaminosuke\ModManager\Support;

use App\Models\Server;
use App\Models\User;
use Kazaminosuke\ModManager\Enums\ProjectOperation;

final class ProjectOperationAuthorizer
{
    public function allows(?User $user, Server $server, ProjectOperation $operation): bool
    {
        if ($user === null) {
            return false;
        }

        // Do not use User::isAdmin(): Pelican reports true for any user with
        // any admin-role permission, which would bypass this operation-level
        // role control. Root Admin is the platform's unconditional admin.
        if ($user->isRootAdmin() || $user->can($operation->roleAbility())) {
            return true;
        }

        if (!(bool) config('pelican-minecraft-modrinth.'.$operation->allowsUserConfigKey(), false)) {
            return false;
        }

        foreach ($operation->requiredFilePermissions() as $permission) {
            if (!$user->can($permission, $server)) {
                return false;
            }
        }

        return true;
    }
}
