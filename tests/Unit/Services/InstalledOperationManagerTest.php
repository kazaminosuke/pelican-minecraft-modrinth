<?php

namespace Kazaminosuke\ModManager\Tests\Unit\Services;

use Kazaminosuke\ModManager\Enums\ProjectType;
use Kazaminosuke\ModManager\Jobs\BulkUpdateInstalledProjects;
use Kazaminosuke\ModManager\Jobs\ScanInstalledProjects;
use Kazaminosuke\ModManager\Services\InstalledOperationManager;
use Kazaminosuke\ModManager\Support\InstalledOperationState;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Mockery;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3).'/src/Enums/ProjectType.php';
require_once dirname(__DIR__, 3).'/src/Support/InstalledOperationState.php';
require_once dirname(__DIR__, 3).'/src/Jobs/BulkUpdateInstalledProjects.php';
require_once dirname(__DIR__, 3).'/src/Jobs/ScanInstalledProjects.php';
require_once dirname(__DIR__, 3).'/src/Services/InstalledOperationManager.php';

class InstalledOperationManagerTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_sync_queue_is_reported_without_dispatching_or_overwriting_state(): void
    {
        $cache = Mockery::mock(CacheRepository::class);
        $cache->shouldReceive('get')->twice()->andReturnNull();
        $config = Mockery::mock(ConfigRepository::class);
        $config->shouldReceive('get')->once()->with('queue.default', 'sync')->andReturn('sync');
        $dispatcher = Mockery::mock(Dispatcher::class);
        $dispatcher->shouldNotReceive('dispatch');

        $result = (new InstalledOperationManager($cache, $config, $dispatcher))
            ->dispatchScan(42, ProjectType::Mod);

        self::assertFalse($result['dispatched']);
        self::assertSame('sync_queue', $result['reason']);
        self::assertNull($result['state']);
    }

    public function test_async_queue_persists_queued_state_and_dispatches_job(): void
    {
        $cache = Mockery::mock(CacheRepository::class);
        $cache->shouldReceive('get')->twice()->andReturnNull();
        $cache->shouldReceive('put')
            ->once()
            ->withArgs(fn (string $key, array $payload): bool => $key === 'mod_manager_operation:v1:42:mod:scan'
                && $payload['status'] === InstalledOperationState::STATUS_QUEUED
                && $payload['result']['force'] === true);
        $config = Mockery::mock(ConfigRepository::class);
        $config->shouldReceive('get')->once()->with('queue.default', 'sync')->andReturn('database');
        $dispatcher = Mockery::mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')
            ->once()
            ->withArgs(fn (ScanInstalledProjects $job): bool => $job->serverId === 42
                && $job->projectType === ProjectType::Mod->value
                && $job->force)
            ->andReturn(1);

        $result = (new InstalledOperationManager($cache, $config, $dispatcher))
            ->dispatchScan(42, ProjectType::Mod, force: true);

        self::assertTrue($result['dispatched']);
        self::assertNull($result['reason']);
        self::assertSame(InstalledOperationState::STATUS_QUEUED, $result['state']->status);
    }

    public function test_async_bulk_update_persists_queued_state_and_dispatches_job(): void
    {
        $cache = Mockery::mock(CacheRepository::class);
        $cache->shouldReceive('get')->twice()->andReturnNull();
        $cache->shouldReceive('put')
            ->once()
            ->withArgs(fn (string $key, array $payload): bool => $key === 'mod_manager_operation:v1:42:mod:bulk_update'
                && $payload['operation'] === InstalledOperationManager::OPERATION_BULK_UPDATE
                && $payload['status'] === InstalledOperationState::STATUS_QUEUED);
        $config = Mockery::mock(ConfigRepository::class);
        $config->shouldReceive('get')->once()->with('queue.default', 'sync')->andReturn('database');
        $dispatcher = Mockery::mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')
            ->once()
            ->withArgs(fn (BulkUpdateInstalledProjects $job): bool => $job->serverId === 42
                && $job->projectType === ProjectType::Mod->value)
            ->andReturn(1);

        $result = (new InstalledOperationManager($cache, $config, $dispatcher))
            ->dispatchBulkUpdate(42, ProjectType::Mod);

        self::assertTrue($result['dispatched']);
        self::assertNull($result['reason']);
        self::assertSame(InstalledOperationManager::OPERATION_BULK_UPDATE, $result['state']->operation);
        self::assertSame(InstalledOperationState::STATUS_QUEUED, $result['state']->status);
    }

    public function test_active_bulk_update_is_not_dispatched_twice(): void
    {
        $activeState = InstalledOperationState::queued(
            InstalledOperationManager::OPERATION_BULK_UPDATE,
            42,
            ProjectType::Mod,
        );
        $cache = Mockery::mock(CacheRepository::class);
        $cache->shouldReceive('get')->once()->andReturn($activeState->toCachePayload());
        $cache->shouldNotReceive('put');
        $config = Mockery::mock(ConfigRepository::class);
        $config->shouldNotReceive('get');
        $dispatcher = Mockery::mock(Dispatcher::class);
        $dispatcher->shouldNotReceive('dispatch');

        $result = (new InstalledOperationManager($cache, $config, $dispatcher))
            ->dispatchBulkUpdate(42, ProjectType::Mod);

        self::assertFalse($result['dispatched']);
        self::assertSame('already_active', $result['reason']);
        self::assertSame(InstalledOperationManager::OPERATION_BULK_UPDATE, $result['state']->operation);
        self::assertTrue($result['state']->isActive());
    }
    public function test_active_scan_prevents_a_bulk_update_from_being_dispatched(): void
    {
        $activeState = InstalledOperationState::queued(
            InstalledOperationManager::OPERATION_SCAN,
            42,
            ProjectType::Mod,
        );
        $cache = Mockery::mock(CacheRepository::class);
        $cache->shouldReceive('get')
            ->twice()
            ->andReturn(null, $activeState->toCachePayload());
        $cache->shouldNotReceive('put');
        $config = Mockery::mock(ConfigRepository::class);
        $config->shouldNotReceive('get');
        $dispatcher = Mockery::mock(Dispatcher::class);
        $dispatcher->shouldNotReceive('dispatch');

        $result = (new InstalledOperationManager($cache, $config, $dispatcher))
            ->dispatchBulkUpdate(42, ProjectType::Mod);

        self::assertFalse($result['dispatched']);
        self::assertSame('already_active', $result['reason']);
        self::assertSame(InstalledOperationManager::OPERATION_SCAN, $result['state']->operation);
        self::assertTrue($result['state']->isActive());
    }
}
