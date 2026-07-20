<?php

namespace Boy132\MinecraftModrinth\Support;

use App\Models\Server;

class MinecraftVersionResolver
{
    public static function resolve(Server $server): ?string
    {
        $version = $server->variables()->where(fn ($builder) => $builder->where('env_variable', 'MINECRAFT_VERSION')->orWhere('env_variable', 'MC_VERSION'))->first()?->server_value;

        if (!$version || $version === 'latest') {
            return config('pelican-minecraft-modrinth.latest_minecraft_version');
        }

        return $version;
    }
}
