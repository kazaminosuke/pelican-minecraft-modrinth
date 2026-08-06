<?php

namespace Kazaminosuke\ModManager\Support;

use Kazaminosuke\ModManager\Enums\ProjectSourceKey;

class LatestVersionLookupRequest
{
    /**
     * @param array<string, string> $hashes
     */
    public function __construct(
        public readonly string $source,
        public readonly string $projectId,
        public readonly string $installedVersionId = '',
        public readonly string $filename = '',
        public readonly array $hashes = [],
    ) {}

    /**
     * @param array<string, mixed> $installedMod
     */
    public static function fromInstalledMod(array $installedMod): ?self
    {
        $projectId = $installedMod['project_id'] ?? null;

        if (!is_string($projectId) || $projectId === '') {
            return null;
        }

        $source = $installedMod['source'] ?? ProjectSourceKey::Modrinth->value;
        if (!is_string($source) || ProjectSourceKey::tryFrom($source) === null) {
            return null;
        }

        $hashes = [];
        foreach (($installedMod['hashes'] ?? []) as $algorithm => $hash) {
            if (is_string($algorithm) && is_string($hash) && $hash !== '') {
                $hashes[strtolower($algorithm)] = strtolower($hash);
            }
        }

        return new self(
            source: $source,
            projectId: $projectId,
            installedVersionId: is_string($installedMod['version_id'] ?? null) ? $installedMod['version_id'] : '',
            filename: is_string($installedMod['filename'] ?? null) ? $installedMod['filename'] : '',
            hashes: $hashes,
        );
    }

    public function key(): string
    {
        return $this->source.':'.$this->projectId;
    }

    public function hash(string $algorithm): ?string
    {
        $hash = $this->hashes[strtolower($algorithm)] ?? null;

        return is_string($hash) && $hash !== '' ? $hash : null;
    }
}
