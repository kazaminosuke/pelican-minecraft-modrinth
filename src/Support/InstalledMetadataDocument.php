<?php

namespace Boy132\MinecraftModrinth\Support;

use Boy132\MinecraftModrinth\Enums\ProjectSourceKey;

/**
 * In-memory representation of `.pelican-mod-manager.json`.
 *
 * The `installed_mods` member deliberately remains compatible with the v1
 * file consumed by older plugin releases. V2 adds optional file signatures
 * and hashes to those entries, plus unresolved files whose hashes can be
 * reused on a later source lookup.
 */
class InstalledMetadataDocument
{
    public const SCHEMA_VERSION = 2;

    /**
     * @param array<int, array<string, mixed>> $installedMods
     * @param array<int, array<string, mixed>> $unresolvedFiles
     * @param array<string, mixed> $extra
     */
    public function __construct(
        protected array $installedMods = [],
        protected array $unresolvedFiles = [],
        protected array $extra = [],
    ) {}

    public static function empty(): self
    {
        return new self();
    }

    public static function fromJson(string $content): ?self
    {
        $metadata = json_decode($content, true);

        return is_array($metadata) ? self::fromArray($metadata) : null;
    }

    /**
     * A document is structurally valid when `installed_mods` exists and is an
     * array. Individual malformed entries are ignored, matching the behavior
     * of the pre-repository metadata reader.
     *
     * @param array<string, mixed> $metadata
     */
    public static function fromArray(array $metadata): ?self
    {
        if (!isset($metadata['installed_mods']) || !is_array($metadata['installed_mods'])) {
            return null;
        }

        $installedMods = [];
        foreach ($metadata['installed_mods'] as $entry) {
            $normalized = self::normalizeInstalledMod($entry);

            if ($normalized !== null) {
                $installedMods[] = $normalized;
            }
        }

        $unresolvedFiles = [];
        if (isset($metadata['unresolved_files']) && is_array($metadata['unresolved_files'])) {
            foreach ($metadata['unresolved_files'] as $entry) {
                $normalized = self::normalizeUnresolvedFile($entry);

                if ($normalized !== null) {
                    $unresolvedFiles[] = $normalized;
                }
            }
        }

        $extra = $metadata;
        unset($extra['schema_version'], $extra['installed_mods'], $extra['unresolved_files']);

        return new self($installedMods, $unresolvedFiles, $extra);
    }

    /** @return array<int, array<string, mixed>> */
    public function installedMods(): array
    {
        return $this->installedMods;
    }

    /** @return array<int, array<string, mixed>> */
    public function unresolvedFiles(): array
    {
        return $this->unresolvedFiles;
    }

    /** @param array<int, array<string, mixed>> $installedMods */
    public function withInstalledMods(array $installedMods): self
    {
        return new self(
            self::normalizeInstalledMods($installedMods),
            $this->unresolvedFiles,
            $this->extra,
        );
    }

    /** @param array<int, array<string, mixed>> $unresolvedFiles */
    public function withUnresolvedFiles(array $unresolvedFiles): self
    {
        return new self(
            $this->installedMods,
            self::normalizeUnresolvedFiles($unresolvedFiles),
            $this->extra,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_merge($this->extra, [
            'schema_version' => self::SCHEMA_VERSION,
            'installed_mods' => array_values($this->installedMods),
            'unresolved_files' => array_values($this->unresolvedFiles),
        ]);
    }

    /**
     * @param mixed $entry
     * @return array<string, mixed>|null
     */
    protected static function normalizeInstalledMod(mixed $entry): ?array
    {
        if (!is_array($entry)) {
            return null;
        }

        $requiredKeys = [
            'project_id',
            'project_slug',
            'project_title',
            'version_id',
            'version_number',
            'filename',
            'installed_at',
        ];

        foreach ($requiredKeys as $key) {
            if (!array_key_exists($key, $entry)) {
                return null;
            }
        }

        $entry['source'] ??= ProjectSourceKey::Modrinth->value;

        return $entry;
    }

    /**
     * @param mixed $entry
     * @return array<string, mixed>|null
     */
    protected static function normalizeUnresolvedFile(mixed $entry): ?array
    {
        if (!is_array($entry) || !isset($entry['filename']) || !is_string($entry['filename']) || $entry['filename'] === '') {
            return null;
        }

        return $entry;
    }

    /**
     * @param array<int, array<string, mixed>> $entries
     * @return array<int, array<string, mixed>>
     */
    protected static function normalizeInstalledMods(array $entries): array
    {
        $normalized = [];

        foreach ($entries as $entry) {
            $entry = self::normalizeInstalledMod($entry);

            if ($entry !== null) {
                $normalized[] = $entry;
            }
        }

        return $normalized;
    }

    /**
     * @param array<int, array<string, mixed>> $entries
     * @return array<int, array<string, mixed>>
     */
    protected static function normalizeUnresolvedFiles(array $entries): array
    {
        $normalized = [];

        foreach ($entries as $entry) {
            $entry = self::normalizeUnresolvedFile($entry);

            if ($entry !== null) {
                $normalized[] = $entry;
            }
        }

        return $normalized;
    }
}
