<?php

namespace Kazaminosuke\ModManager\Tests\Unit\Services;

use Illuminate\Config\Repository as LaravelConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Log\Context\Repository as ContextRepository;
use Illuminate\Support\Facades\Facade;
use Kazaminosuke\ModManager\Enums\ProjectType;
use Kazaminosuke\ModManager\Jobs\BulkUpdateInstalledProjects;
use Kazaminosuke\ModManager\Jobs\ScanInstalledProjects;
use Kazaminosuke\ModManager\Services\InstalledOperationManager;
use Kazaminosuke\ModManager\Support\InstalledOperationState;
use Mockery;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3).'/src/Enums/ProjectType.php';
require_once dirname(__DIR__, 3).'/src/Support/InstalledOperationState.php';
require_once dirname(__DIR__, 3).'/src/Jobs/BulkUpdateInstalledProjects.php';
require_once dirname(__DIR__, 3).'/src/Jobs/ScanInstalledProjects.php';
require_once dirname(__DIR__, 3).'/src/Services/InstalledOperationManager.php';

class InstalledOperationManagerTest extends TestCase
{
    private ?Container $previousContainer = null;

    private mixed $previousFacadeApplication = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousContainer = Container::getInstance();
        $this->previousFacadeApplication = Facade::getFacadeApplication();
    }

    protected function tearDown(): void
    {
        Container::setInstance($this->previousContainer);
        Facade::setFacadeApplication($this->previousFacadeApplication);
        Mockery::close();

        parent::tearDown();
    }

    public function test_sync_queue_is_reported_without_dispatching_or_overwriting_state(): void
    {
        $cache = Mockery::mock(CacheRepository::class);
        $cache->shouldReceive('get')->twice()->andReturnNull();
        $config = Mockery::mock(ConfigRepository::class);
        $config->shouldReceive('get')->once()->with('queue.default', 'sync')->andReturn('sync');
        $result = (new InstalledOperationManager($cache, $config))
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
        $dispatcher = $this->bindDispatcher($cache);
        $this->expectUniqueLock($cache, 'mod-manager:scan:42:mod', 600);
        $dispatcher->shouldReceive('dispatch')
            ->once()
            ->withArgs(fn (ScanInstalledProjects $job): bool => $job->serverId === 42
                && $job->projectType === ProjectType::Mod->value
                && $job->force)
            ->andReturn(1);

        $result = (new InstalledOperationManager($cache, $config))
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
        $dispatcher = $this->bindDispatcher($cache);
        $this->expectUniqueLock($cache, 'mod-manager:bulk-update:42:mod', 1200);
        $dispatcher->shouldReceive('dispatch')
            ->once()
            ->withArgs(fn (BulkUpdateInstalledProjects $job): bool => $job->serverId === 42
                && $job->projectType === ProjectType::Mod->value)
            ->andReturn(1);

        $result = (new InstalledOperationManager($cache, $config))
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
        $result = (new InstalledOperationManager($cache, $config))
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
        $result = (new InstalledOperationManager($cache, $config))
            ->dispatchBulkUpdate(42, ProjectType::Mod);

        self::assertFalse($result['dispatched']);
        self::assertSame('already_active', $result['reason']);
        self::assertSame(InstalledOperationManager::OPERATION_SCAN, $result['state']->operation);
        self::assertTrue($result['state']->isActive());
    }

    private function bindDispatcher(CacheRepository $cache): Dispatcher
    {
        $dispatcher = Mockery::mock(Dispatcher::class);
        $context = Mockery::mock(ContextRepository::class);
        $exceptionHandler = Mockery::mock(ExceptionHandler::class);
        $context->shouldReceive('addHidden')->zeroOrMoreTimes();
        $context->shouldReceive('forgetHidden')->zeroOrMoreTimes();
        $exceptionHandler->shouldReceive('report')->zeroOrMoreTimes();
        $dispatchConfig = new LaravelConfigRepository([
            'cache' => ['default' => 'array'],
        ]);
        $container = new Container();
        $container->instance(CacheRepository::class, $cache);
        $container->instance(ConfigRepository::class, $dispatchConfig);
        $container->instance('config', $dispatchConfig);
        $container->instance(Dispatcher::class, $dispatcher);
        $container->instance(ContextRepository::class, $context);
        $container->instance(ExceptionHandler::class, $exceptionHandler);
        Container::setInstance($container);
        Facade::setFacadeApplication($container);

        return $dispatcher;
    }

    private function expectUniqueLock(CacheRepository $cache, string $uniqueId, int $uniqueFor): void
    {
        $lock = Mockery::mock();
        $lock->shouldReceive('get')->once()->andReturnTrue();
        $cache->shouldReceive('lock')
            ->once()
            ->withArgs(static fn (mixed $key, mixed $seconds): bool => is_string($key)
                && str_ends_with($key, ':'.$uniqueId)
                && $seconds === $uniqueFor)
            ->andReturn($lock);
    }
}
