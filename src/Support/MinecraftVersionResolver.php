<?php

namespace Kazaminosuke\ModManager\Support;

use App\Models\Server;

class MinecraftVersionResolver
{
    /** @var array<int|string, string|null> */
    private static array $resolvedVersions = [];

    public static function resolve(Server $server): ?string
    {
        $key = $server->getKey();
        $serverKey = is_int($key) || is_string($key)
            ? (string) $key
            : 'object:'.spl_object_id($server);

        if (array_key_exists($serverKey, self::$resolvedVersions)) {
            return self::$resolvedVersions[$serverKey];
        }

        $version = $server->variables()
            ->where(fn ($builder) => $builder->where('env_variable', 'MINECRAFT_VERSION')->orWhere('env_variable', 'MC_VERSION'))
            ->first()
            ?->server_value;

        if (!$version || $version === 'latest') {
            $version = config('pelican-minecraft-modrinth.latest_minecraft_version');
        }

        return self::$resolvedVersions[$serverKey] = is_string($version) ? $version : null;
    }

    public static function clear(): void
    {
        self::$resolvedVersions = [];
    }
}
