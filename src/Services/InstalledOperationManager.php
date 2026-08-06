<?php

namespace Kazaminosuke\ModManager\Services;

use App\Models\Server;
use DateTimeImmutable;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Kazaminosuke\ModManager\Enums\ProjectType;
use Kazaminosuke\ModManager\Jobs\BulkUpdateInstalledProjects;
use Kazaminosuke\ModManager\Jobs\ScanInstalledProjects;
use Kazaminosuke\ModManager\Support\InstalledOperationState;
use Throwable;

final class InstalledOperationManager
{
    public const OPERATION_SCAN = 'scan';

    public const OPERATION_BULK_UPDATE = 'bulk_update';

    private const CACHE_PREFIX = 'mod_manager_operation:v1';

    private const CACHE_TTL_MINUTES = 120;

    public function __construct(
        private readonly CacheRepository $cache,
        private readonly ConfigRepository $config,
        private readonly Dispatcher $dispatcher,
    ) {}

    public function supportsAsyncDispatch(): bool
    {
        return !in_array(
            (string) $this->config->get('queue.default', 'sync'),
            ['sync', 'null'],
            true,
        );
    }

    /**
     * The manager never falls back to synchronous execution. Callers can use
     * the reason to show an operator-facing queue configuration warning.
     *
     * @return array{dispatched: bool, reason: null|'already_active'|'sync_queue'|'dispatch_failed', state: ?InstalledOperationState}
     */
    public function dispatchScan(
        Server|int $server,
        ProjectType $projectType,
        bool $force = false,
    ): array {
        $serverId = $this->serverId($server);
        $current = $this->operationStateOrActive($serverId, $projectType, self::OPERATION_SCAN);

        if ($current?->isActive()) {
            return [
                'dispatched' => false,
                'reason' => 'already_active',
                'state' => $current,
            ];
        }

        if (!$this->supportsAsyncDispatch()) {
            return [
                'dispatched' => false,
                'reason' => 'sync_queue',
                'state' => $current,
            ];
        }

        $state = $this->queue($serverId, $projectType, self::OPERATION_SCAN, [
            'force' => $force,
        ]);

        try {
            $this->dispatcher->dispatch(new ScanInstalledProjects(
                serverId: $serverId,
                projectType: $projectType->value,
                force: $force,
            ));
        } catch (Throwable $exception) {
            report($exception);

            $state = $this->fail(
                $serverId,
                $projectType,
                self::OPERATION_SCAN,
                'dispatch_failed',
            );

            return [
                'dispatched' => false,
                'reason' => 'dispatch_failed',
                'state' => $state,
            ];
        }

        return [
            'dispatched' => true,
            'reason' => null,
            'state' => $state,
        ];
    }

    public function state(
        Server|int $server,
        ProjectType $projectType,
        string $operation,
    ): ?InstalledOperationState {
        return InstalledOperationState::fromCachePayload(
            $this->cache->get($this->cacheKey($this->serverId($server), $projectType, $operation)),
        );
    }

    /**
     * @param array<string, mixed> $result
     */
    public function queue(
        Server|int $server,
        ProjectType $projectType,
        string $operation,
        array $result = [],
    ): InstalledOperationState {
        return $this->put(InstalledOperationState::queued(
            operation: $operation,
            serverId: $this->serverId($server),
            projectType: $projectType,
            result: $result,
        ));
    }

    public function start(
        Server|int $server,
        ProjectType $projectType,
        string $operation,
        ?int $total = null,
    ): InstalledOperationState {
        $serverId = $this->serverId($server);
        $state = $this->state($serverId, $projectType, $operation)
            ?? InstalledOperationState::queued($operation, $serverId, $projectType);

        return $this->put($state->running($total));
    }

    public function progress(
        Server|int $server,
        ProjectType $projectType,
        string $operation,
        int $progress,
        ?int $total = null,
    ): InstalledOperationState {
        $state = $this->start($server, $projectType, $operation, $total);

        return $this->put($state->withProgress($progress, $total));
    }

    /**
     * @param array<string, mixed> $result
     */
    public function defer(
        Server|int $server,
        ProjectType $projectType,
        string $operation,
        array $result = [],
    ): InstalledOperationState {
        $serverId = $this->serverId($server);
        $state = $this->state($serverId, $projectType, $operation)
            ?? InstalledOperationState::queued($operation, $serverId, $projectType);

        return $this->put($state->requeue($result));
    }

    /**
     * @param array<string, mixed> $result
     */
    public function complete(
        Server|int $server,
        ProjectType $projectType,
        string $operation,
        array $result = [],
    ): InstalledOperationState {
        $serverId = $this->serverId($server);
        $state = $this->state($serverId, $projectType, $operation)
            ?? InstalledOperationState::queued($operation, $serverId, $projectType);

        return $this->put($state->completed($result));
    }

    /**
     * @param array<string, mixed> $result
     */
    public function fail(
        Server|int $server,
        ProjectType $projectType,
        string $operation,
        string $error,
        array $result = [],
    ): InstalledOperationState {
        $serverId = $this->serverId($server);
        $state = $this->state($serverId, $projectType, $operation)
            ?? InstalledOperationState::queued($operation, $serverId, $projectType);

        return $this->put($state->failed($error, $result));
    }

    public function forget(
        Server|int $server,
        ProjectType $projectType,
        string $operation,
    ): void {
        $this->cache->forget($this->cacheKey($this->serverId($server), $projectType, $operation));
    }

    private function operationStateOrActive(
        int $serverId,
        ProjectType $projectType,
        string $requestedOperation,
    ): ?InstalledOperationState {
        $current = $this->state($serverId, $projectType, $requestedOperation);

        if ($current?->isActive()) {
            return $current;
        }

        $otherOperation = $requestedOperation === self::OPERATION_SCAN
            ? self::OPERATION_BULK_UPDATE
            : self::OPERATION_SCAN;
        $other = $this->state($serverId, $projectType, $otherOperation);

        return $other?->isActive() ? $other : $current;
    }

    private function put(InstalledOperationState $state): InstalledOperationState
    {
        $this->cache->put(
            $this->cacheKey($state->serverId, $state->projectType, $state->operation),
            $state->toCachePayload(),
            new DateTimeImmutable('+'.self::CACHE_TTL_MINUTES.' minutes'),
        );

        return $state;
    }

    private function cacheKey(
        int $serverId,
        ProjectType $projectType,
        string $operation,
    ): string {
        return implode(':', [
            self::CACHE_PREFIX,
            $serverId,
            $projectType->value,
            $operation,
        ]);
    }

    private function serverId(Server|int $server): int
    {
        $serverId = $server instanceof Server ? (int) $server->getKey() : $server;

        if ($serverId < 1) {
            throw new \InvalidArgumentException('The installed operation server ID must be positive.');
        }

        return $serverId;
    }

    /**
     * @return array{dispatched: bool, reason: null|'already_active'|'sync_queue'|'dispatch_failed', state: ?InstalledOperationState}
     */
    public function dispatchBulkUpdate(
        Server|int $server,
        ProjectType $projectType,
    ): array {
        $serverId = $this->serverId($server);
        $current = $this->operationStateOrActive($serverId, $projectType, self::OPERATION_BULK_UPDATE);

        if ($current?->isActive()) {
            return [
                'dispatched' => false,
                'reason' => 'already_active',
                'state' => $current,
            ];
        }

        if (!$this->supportsAsyncDispatch()) {
            return [
                'dispatched' => false,
                'reason' => 'sync_queue',
                'state' => $current,
            ];
        }

        $state = $this->queue($serverId, $projectType, self::OPERATION_BULK_UPDATE);

        try {
            $this->dispatcher->dispatch(new BulkUpdateInstalledProjects(
                serverId: $serverId,
                projectType: $projectType->value,
            ));
        } catch (Throwable $exception) {
            report($exception);

            $state = $this->fail(
                $serverId,
                $projectType,
                self::OPERATION_BULK_UPDATE,
                'dispatch_failed',
            );

            return [
                'dispatched' => false,
                'reason' => 'dispatch_failed',
                'state' => $state,
            ];
        }

        return [
            'dispatched' => true,
            'reason' => null,
            'state' => $state,
        ];
    }
}
