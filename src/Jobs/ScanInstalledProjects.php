<?php

namespace Boy132\MinecraftModrinth\Jobs;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use Boy132\MinecraftModrinth\Enums\ModrinthProjectType;
use Boy132\MinecraftModrinth\Services\InstalledOperationManager;
use Boy132\MinecraftModrinth\Services\MinecraftModrinthService;
use Illuminate\Container\Container;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

final class ScanInstalledProjects implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 240;

    public bool $failOnTimeout = true;

    public int $uniqueFor = 600;

    public function __construct(
        public readonly int $serverId,
        public readonly string $projectType,
        public readonly bool $force = false,
    ) {}

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [5, 15, 30, 60];
    }

    public function uniqueId(): string
    {
        return "mod-manager:scan:{$this->serverId}:{$this->projectType}";
    }

    public function handle(
        DaemonFileRepository $fileRepository,
        MinecraftModrinthService $service,
        InstalledOperationManager $operations,
        CacheRepository $cache,
    ): void {
        $type = ModrinthProjectType::tryFrom($this->projectType);

        if (!$type) {
            return;
        }

        /** @var Server|null $server */
        $server = Server::query()->with('egg')->find($this->serverId);

        if (!$server) {
            $operations->fail(
                $this->serverId,
                $type,
                InstalledOperationManager::OPERATION_SCAN,
                'server_not_found',
            );

            return;
        }

        $operations->start(
            $server,
            $type,
            InstalledOperationManager::OPERATION_SCAN,
        );

        try {
            // A released job keeps force=true, so only the first attempt should
            // invalidate a completed scan produced by another worker.
            if ($this->force && $this->attempts() <= 1) {
                $cache->forget($service->getHashScanCacheKey($server, $type));
            }

            $result = $service->scanAndImportModsResult($server, $fileRepository, $type);

            if (!$result->successful && $result->failure === 'scan_in_progress') {
                if ($this->attempts() >= $this->tries) {
                    $operations->fail(
                        $server,
                        $type,
                        InstalledOperationManager::OPERATION_SCAN,
                        'scan_busy_timeout',
                    );

                    return;
                }

                $operations->defer(
                    $server,
                    $type,
                    InstalledOperationManager::OPERATION_SCAN,
                    ['reason' => 'scan_in_progress'],
                );
                $this->release($this->retryDelay());

                return;
            }

            $summary = [
                'disk_file_count' => $result->diskFileCount,
                'unknown_files_count' => count($result->unknownFiles),
                'cache_hit' => $result->cacheHit,
            ];

            if (!$result->successful) {
                $operations->fail(
                    $server,
                    $type,
                    InstalledOperationManager::OPERATION_SCAN,
                    $result->failure ?? 'scan_failed',
                    $summary,
                );

                return;
            }

            $operations->progress(
                $server,
                $type,
                InstalledOperationManager::OPERATION_SCAN,
                $result->diskFileCount,
                $result->diskFileCount,
            );
            $operations->complete(
                $server,
                $type,
                InstalledOperationManager::OPERATION_SCAN,
                $summary,
            );
        } catch (Throwable $exception) {
            report($exception);

            $operations->fail(
                $server,
                $type,
                InstalledOperationManager::OPERATION_SCAN,
                'scan_exception',
            );
        }
    }

    public function failed(?Throwable $exception): void
    {
        $type = ModrinthProjectType::tryFrom($this->projectType);

        if (!$type) {
            return;
        }

        Container::getInstance()->make(InstalledOperationManager::class)->fail(
            $this->serverId,
            $type,
            InstalledOperationManager::OPERATION_SCAN,
            $exception === null ? 'scan_job_failed' : 'scan_job_exception',
        );
    }

    private function retryDelay(): int
    {
        $backoff = $this->backoff();

        return $backoff[min(max(0, $this->attempts() - 1), count($backoff) - 1)];
    }
}
