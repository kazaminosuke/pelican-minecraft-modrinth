<?php

namespace Kazaminosuke\ModManager\Support;

use App\Models\Server;

/**
 * Small integer "generation" stamps baked into otherwise content-addressed
 * cache keys (see ProjectSourceRegistry::fetchProjectsMap() and
 * HangarSource::findVersionEntryByHash()), so a whole family of cache
 * entries can be invalidated by bumping one stamp instead of needing to
 * enumerate or pattern-delete the underlying keys - which isn't reliably
 * possible across Laravel's cache drivers (the file and database drivers
 * have no wildcard delete; only redis/memcached support tags). Bumping a
 * stamp doesn't remove the old entries, it just means they're never looked
 * up again under the new stamp; they still expire on their own TTL.
 */
class CacheVersion
{
    protected const HYDRATION_KEY_PREFIX = 'mmr_cache_gen:hydrate:';

    protected const HANGAR_HASH_KEY = 'mmr_cache_gen:hangar_hash';

    public static function hydration(Server $server): int
    {
        return (int) cache()->get(self::HYDRATION_KEY_PREFIX.$server->id, 0);
    }

    public static function bumpHydration(Server $server): void
    {
        self::bump(self::HYDRATION_KEY_PREFIX.$server->id);
    }

    /**
     * Bumps every server's hydration generation at once - there's no single
     * server to scope to from the plugin settings screen's "clear cache"
     * action.
     *
     * @return int Number of servers bumped, for the confirmation notification.
     */
    public static function bumpAllHydration(): int
    {
        $ids = Server::query()->pluck('id');

        foreach ($ids as $id) {
            self::bump(self::HYDRATION_KEY_PREFIX.$id);
        }

        return $ids->count();
    }

    public static function hangarHash(): int
    {
        return (int) cache()->get(self::HANGAR_HASH_KEY, 0);
    }

    public static function bumpHangarHash(): void
    {
        self::bump(self::HANGAR_HASH_KEY);
    }

    private static function bump(string $key): void
    {
        // A timestamp has only second precision, so several metadata writes in
        // one bulk update could otherwise retain the same display-cache key.
        // Redis INCR creates a missing key atomically; the fallback preserves
        // compatibility with cache drivers that cannot increment values.
        if (cache()->increment($key) === false) {
            cache()->forever($key, 1);
        }
    }
}
