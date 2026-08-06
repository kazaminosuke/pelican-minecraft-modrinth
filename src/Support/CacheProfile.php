<?php

namespace Kazaminosuke\ModManager\Support;

/**
 * Cache lifetimes are centralized here so every upstream source applies the
 * same freshness policy for equivalent data.
 */
enum CacheProfile: string
{
    case HashMatch = 'hash_match';
    case VersionFile = 'version_file';
    case Search = 'search';
    case ProjectMetadata = 'project_metadata';
    case InstalledLatest = 'installed_latest';
    case Identity = 'identity';

    public function freshTtlSeconds(): int
    {
        return match ($this) {
            self::HashMatch, self::VersionFile => 30 * 24 * 60 * 60,
            self::Search => 10 * 60,
            self::ProjectMetadata => 24 * 60 * 60,
            self::InstalledLatest => 30 * 60,
            self::Identity => 7 * 24 * 60 * 60,
        };
    }

    /**
     * Null denotes an entry whose stale copy is retained indefinitely.
     */
    public function staleTtlSeconds(): ?int
    {
        return match ($this) {
            self::HashMatch, self::VersionFile => null,
            self::Search, self::InstalledLatest => 24 * 60 * 60,
            self::ProjectMetadata => 7 * 24 * 60 * 60,
            self::Identity => 30 * 24 * 60 * 60,
        };
    }

    public function inlineBudgetSeconds(): float
    {
        return 1.5;
    }

    public function backgroundTimeoutSeconds(): float
    {
        return 10.0;
    }

    public function failureMarkerTtlSeconds(): int
    {
        return 30;
    }
}
