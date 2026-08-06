<?php

namespace Kazaminosuke\ModManager\Repositories;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use Kazaminosuke\ModManager\Support\CacheVersion;
use Kazaminosuke\ModManager\Support\InstalledMetadataDocument;
use Kazaminosuke\ModManager\Support\InstalledMetadataReadResult;
use Kazaminosuke\ModManager\Support\InstalledMetadataReadStatus;
use Closure;
use Exception;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Support\Facades\Cache;

class InstalledMetadataRepository
{
    protected const CURRENT_FILENAME = '.pelican-mod-manager.json';

    protected const LEGACY_FILENAME = '.modrinth-metadata.json';

    /**
     * Read the current document, falling back to the legacy document only
     * when the current file is missing, unavailable, or invalid.
     *
     * A valid current document with an empty `installed_mods` array is
     * authoritative and must never resurrect entries from the legacy file.
     */
    public function read(Server $server, DaemonFileRepository $fileRepository, string $folder): InstalledMetadataReadResult
    {
        $current = $this->readPath(
            $server,
            $fileRepository,
            $this->metadataPath($folder, self::CURRENT_FILENAME),
            InstalledMetadataReadStatus::Current,
        );

        if ($current->isAuthoritative()) {
            return $current;
        }

        $legacy = $this->readPath(
            $server,
            $fileRepository,
            $this->metadataPath($folder, self::LEGACY_FILENAME),
            InstalledMetadataReadStatus::Legacy,
        );

        if ($legacy->isAuthoritative()) {
            return $legacy;
        }

        return new InstalledMetadataReadResult(
            InstalledMetadataDocument::empty(),
            $this->selectFailureStatus($current->status, $legacy->status),
        );
    }

    /**
     * Replace the complete metadata document under the metadata lock.
     *
     * Scanners should build their result in memory and call this once rather
     * than writing one project at a time.
     */
    public function replace(
        Server $server,
        DaemonFileRepository $fileRepository,
        string $folder,
        InstalledMetadataDocument $document,
    ): bool {
        try {
            return $this->withinLock($server, function () use ($server, $fileRepository, $folder, $document): bool {
                return $this->write($server, $fileRepository, $folder, $document);
            }) === true;
        } catch (Exception $exception) {
            report($exception);

            return false;
        }
    }

    /**
     * Atomically read, transform, and write the document. This is intended for
     * individual install, update, and remove actions which must not overwrite
     * concurrent changes.
     *
     * @param Closure(InstalledMetadataDocument): InstalledMetadataDocument $callback
     */
    public function mutate(
        Server $server,
        DaemonFileRepository $fileRepository,
        string $folder,
        Closure $callback,
    ): bool {
        try {
            return $this->withinLock($server, function () use ($server, $fileRepository, $folder, $callback): bool {
                $result = $this->read($server, $fileRepository, $folder);

                if (!$result->isAuthoritative() && $result->status !== InstalledMetadataReadStatus::Missing) {
                    return false;
                }

                $document = $callback($result->document);

                return $this->write($server, $fileRepository, $folder, $document);
            }) === true;
        } catch (Exception $exception) {
            report($exception);

            return false;
        }
    }

    protected function readPath(
        Server $server,
        DaemonFileRepository $fileRepository,
        string $path,
        InstalledMetadataReadStatus $successStatus,
    ): InstalledMetadataReadResult {
        try {
            $content = $fileRepository->setServer($server)->getContent($path);
        } catch (FileNotFoundException) {
            return new InstalledMetadataReadResult(
                InstalledMetadataDocument::empty(),
                InstalledMetadataReadStatus::Missing,
            );
        } catch (Exception $exception) {
            report($exception);

            return new InstalledMetadataReadResult(
                InstalledMetadataDocument::empty(),
                InstalledMetadataReadStatus::Unavailable,
            );
        }

        $document = InstalledMetadataDocument::fromJson($content);

        if ($document === null) {
            return new InstalledMetadataReadResult(
                InstalledMetadataDocument::empty(),
                InstalledMetadataReadStatus::Invalid,
            );
        }

        return new InstalledMetadataReadResult($document, $successStatus);
    }

    protected function write(
        Server $server,
        DaemonFileRepository $fileRepository,
        string $folder,
        InstalledMetadataDocument $document,
    ): bool {
        $content = json_encode(
            $document->toArray(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );

        $response = $fileRepository->setServer($server)->putContent(
            $this->metadataPath($folder, self::CURRENT_FILENAME),
            $content,
        );

        if ($response->failed()) {
            return false;
        }

        $this->bumpHydration($server);

        return true;
    }

    protected function withinLock(Server $server, Closure $callback): mixed
    {
        return Cache::lock("modrinth_metadata:{$server->id}", 10)->block(5, $callback);
    }

    protected function bumpHydration(Server $server): void
    {
        CacheVersion::bumpHydration($server);
    }

    protected function metadataPath(string $folder, string $filename): string
    {
        $folder = trim($folder, " \t\n\r\0\x0B/\\");

        return $folder === '' ? $filename : "{$folder}/{$filename}";
    }

    protected function selectFailureStatus(
        InstalledMetadataReadStatus $current,
        InstalledMetadataReadStatus $legacy,
    ): InstalledMetadataReadStatus {
        if ($current === InstalledMetadataReadStatus::Unavailable || $legacy === InstalledMetadataReadStatus::Unavailable) {
            return InstalledMetadataReadStatus::Unavailable;
        }

        if ($current === InstalledMetadataReadStatus::Invalid || $legacy === InstalledMetadataReadStatus::Invalid) {
            return InstalledMetadataReadStatus::Invalid;
        }

        return InstalledMetadataReadStatus::Missing;
    }
}
