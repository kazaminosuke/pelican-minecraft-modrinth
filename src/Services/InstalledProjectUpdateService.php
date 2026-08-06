<?php

namespace Kazaminosuke\ModManager\Services;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use Exception;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Kazaminosuke\ModManager\Enums\ProjectSourceKey;
use Kazaminosuke\ModManager\Enums\ProjectType;
use Kazaminosuke\ModManager\Support\LatestVersionLookupResult;
use Throwable;

final class InstalledProjectUpdateService
{
    public function __construct(
        private readonly InstalledProjectService $minecraft,
        private readonly VersionLookupCoordinator $versions,
        private readonly CacheRepository $cache,
    ) {}

    /**
     * @param callable(int, int): void|null $progress
     * @return array{total: int, updated: int, failed: int, skipped: int}
     */
    public function updateAll(
        Server $server,
        DaemonFileRepository $fileRepository,
        ProjectType $type,
        ?callable $progress = null,
    ): array {
        $metadata = $this->minecraft->getInstalledMetadataReadResult($server, $fileRepository, $type);

        if (!$metadata->isAuthoritative()) {
            throw new Exception('Installed metadata is unavailable.');
        }

        $installedMods = $metadata->document->installedMods();
        $total = count($installedMods);
        $result = $this->versions->lookupInstalled($installedMods, $server, $type);
        $updated = 0;
        $failed = 0;
        $skipped = 0;

        foreach ($installedMods as $index => $installedMod) {
            try {
                $latestVersion = $this->latestVersionFor($installedMod, $result);

                if ($latestVersion === null || ($installedMod['version_id'] ?? null) === ($latestVersion['id'] ?? null)) {
                    $skipped++;
                } else {
                    $this->installVersion($server, $fileRepository, $type, $installedMod, $latestVersion);
                    $updated++;
                }
            } catch (Throwable $exception) {
                report($exception);
                $failed++;
            } finally {
                if ($progress !== null) {
                    $progress($index + 1, $total);
                }
            }
        }

        if ($updated > 0) {
            $this->cache->forget($this->minecraft->getHashScanCacheKey($server, $type));
        }

        return [
            'total' => $total,
            'updated' => $updated,
            'failed' => $failed,
            'skipped' => $skipped,
        ];
    }

    /**
     * @param array<string, mixed> $installedMod
     * @return array<string, mixed>|null
     */
    private function latestVersionFor(array $installedMod, LatestVersionLookupResult $result): ?array
    {
        $projectId = $installedMod['project_id'] ?? null;
        $source = $installedMod['source'] ?? ProjectSourceKey::Modrinth->value;

        if (!is_string($projectId) || $projectId === '' || !is_string($source)) {
            throw new Exception('Installed metadata is missing its project identity.');
        }

        $key = "{$source}:{$projectId}";

        if (isset($result->failures()[$key])) {
            throw new Exception("Latest version lookup failed for [{$key}].");
        }

        return $result->version($key);
    }

    /**
     * @param array<string, mixed> $installedMod
     * @param array<string, mixed> $version
     */
    private function installVersion(
        Server $server,
        DaemonFileRepository $fileRepository,
        ProjectType $type,
        array $installedMod,
        array $version,
    ): void {
        $projectId = $this->requiredString($installedMod, 'project_id');
        $source = ProjectSourceKey::tryFrom((string) ($installedMod['source'] ?? '')) ?? ProjectSourceKey::Modrinth;
        $primaryFile = $this->primaryFile($version['files'] ?? null);
        $newFilename = $this->safeFilename($this->requiredString($primaryFile, 'filename'));
        $oldFilename = $this->safeFilename($this->requiredString($installedMod, 'filename'));
        $folder = $this->minecraft->getProjectFolder($server, $fileRepository, $type);

        $fileRepository
            ->setServer($server)
            ->pull($this->requiredString($primaryFile, 'url'), $folder)
            ->throw();

        $saved = $this->minecraft->saveModMetadata(
            $server,
            $fileRepository,
            $projectId,
            $this->requiredString($installedMod, 'project_slug'),
            $this->requiredString($installedMod, 'project_title'),
            $this->requiredString($version, 'id'),
            $this->requiredString($version, 'version_number'),
            $newFilename,
            is_string($installedMod['author'] ?? null) ? $installedMod['author'] : null,
            $type,
            $source,
        );

        if (!$saved) {
            $this->deleteFileQuietly($server, $fileRepository, $folder, $newFilename);

            throw new Exception("Failed to persist metadata for [{$projectId}].");
        }

        if ($oldFilename === $newFilename) {
            return;
        }

        try {
            $fileRepository
                ->setServer($server)
                ->deleteFiles($folder, [$oldFilename])
                ->throw();
        } catch (Throwable $deleteException) {
            $this->deleteFileQuietly($server, $fileRepository, $folder, $newFilename);

            if (!$this->minecraft->saveModMetadata(
                $server,
                $fileRepository,
                $projectId,
                $this->requiredString($installedMod, 'project_slug'),
                $this->requiredString($installedMod, 'project_title'),
                $this->requiredString($installedMod, 'version_id'),
                $this->requiredString($installedMod, 'version_number'),
                $oldFilename,
                is_string($installedMod['author'] ?? null) ? $installedMod['author'] : null,
                $type,
                $source,
            )) {
                report(new Exception("Failed to restore metadata for [{$projectId}]."));
            }

            throw $deleteException;
        }
    }

    /** @return array<string, mixed> */
    private function primaryFile(mixed $files): array
    {
        if (!is_array($files)) {
            throw new Exception('Latest version has no files.');
        }

        foreach ($files as $file) {
            if (is_array($file) && ($file['primary'] ?? false) === true) {
                return $file;
            }
        }

        throw new Exception('Latest version has no primary file.');
    }

    /** @param array<string, mixed> $data */
    private function requiredString(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        if (!is_string($value) || $value === '') {
            throw new Exception("Missing required value [{$key}].");
        }

        return $value;
    }

    private function safeFilename(string $filename): string
    {
        if ($filename === '' || $filename === '.' || str_contains($filename, "\0") || str_contains($filename, '..') || str_contains($filename, '/') || str_contains($filename, '\\')) {
            throw new Exception('Invalid filename.');
        }

        return basename($filename);
    }

    private function deleteFileQuietly(
        Server $server,
        DaemonFileRepository $fileRepository,
        string $folder,
        string $filename,
    ): void {
        try {
            $fileRepository
                ->setServer($server)
                ->deleteFiles($folder, [$filename])
                ->throw();
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
