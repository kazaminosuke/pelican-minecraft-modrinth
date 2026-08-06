<?php

namespace Kazaminosuke\ModManager\Tests\Unit\Support;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as LaravelCacheRepository;
use Illuminate\Config\Repository as LaravelConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Log\Context\Repository as ContextRepository;
use Illuminate\Support\Facades\Facade;
use InvalidArgumentException;
use Kazaminosuke\ModManager\Contracts\ProjectSourceInterface;
use Kazaminosuke\ModManager\Contracts\SourceFetchExecutorInterface;
use Kazaminosuke\ModManager\Contracts\SourceFetchHandlerInterface;
use Kazaminosuke\ModManager\Exceptions\PartialSourceFetchException;
use Kazaminosuke\ModManager\Jobs\RevalidateSourceCache;
use Kazaminosuke\ModManager\Services\InstalledOperationManager;
use Kazaminosuke\ModManager\Support\CacheProfile;
use Kazaminosuke\ModManager\Support\ProjectSourceRegistry;
use Kazaminosuke\ModManager\Support\SourceCache;
use Kazaminosuke\ModManager\Support\SourceFetchExecutor;
use Kazaminosuke\ModManager\Support\SourceFetchSpec;
use Mockery;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class SourceCacheTest extends TestCase
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

    public function test_fresh_hit_returns_cached_data_without_fetching(): void
    {
        $cache = $this->cache();
        $spec = $this->spec();
        $data = ['hits' => [['project_id' => 'one']], 'total_hits' => 1];
        $cache->put($spec->cacheKey(), $this->entry($data, time() + 60), 300);
        $executor = Mockery::mock(SourceFetchExecutorInterface::class);
        $executor->shouldNotReceive('fetch');
        $executor->shouldNotReceive('emptyResult');

        $result = $this->sourceCache($cache, 'database', $executor)
            ->swr($spec, CacheProfile::Search);

        self::assertSame($data, $result);
    }

    public function test_stale_hit_returns_immediately_and_dispatches_one_unique_job_for_repeated_reads(): void
    {
        $cache = $this->cache();
        $spec = $this->spec();
        $stale = ['hits' => [['project_id' => 'stale']], 'total_hits' => 1];
        $cache->put($spec->cacheKey(), $this->entry($stale, time() - 1), 300);
        $executor = Mockery::mock(SourceFetchExecutorInterface::class);
        $executor->shouldNotReceive('fetch');
        $executor->shouldNotReceive('emptyResult');
        $dispatcher = $this->prepareDispatchContainer($cache);
        $dispatcher->shouldReceive('dispatch')
            ->once()
            ->withArgs(fn (RevalidateSourceCache $job): bool => $job->uniqueId() === $spec->cacheKey())
            ->andReturnNull();

        $sourceCache = $this->sourceCache($cache, 'database', $executor);

        self::assertSame($stale, $sourceCache->swr($spec, CacheProfile::Search));
        self::assertSame($stale, $sourceCache->swr($spec, CacheProfile::Search));
    }

    public function test_fetch_failure_returns_stale_data_and_writes_failure_marker(): void
    {
        $cache = $this->cache();
        $spec = $this->spec();
        $stale = ['hits' => [['project_id' => 'stale']], 'total_hits' => 1];
        $cache->put($spec->cacheKey(), $this->entry($stale, time() - 1), 300);
        $executor = Mockery::mock(SourceFetchExecutorInterface::class);
        $executor->shouldReceive('fetch')
            ->once()
            ->with($spec, CacheProfile::Search->inlineBudgetSeconds())
            ->andThrow(new RuntimeException('offline'));
        $executor->shouldNotReceive('emptyResult');

        $result = $this->sourceCache($cache, 'sync', $executor)
            ->swr($spec, CacheProfile::Search);

        self::assertSame($stale, $result);
        self::assertIsArray($cache->get($spec->cacheKey().':failure:v1'));
        self::assertSame($stale, $cache->get($spec->cacheKey())['data']);
    }

    public function test_fetch_failure_without_stale_returns_empty_marks_failure_and_queues_one_retry(): void
    {
        $cache = $this->cache();
        $spec = $this->spec();
        $empty = ['hits' => [], 'total_hits' => 0];
        $executor = Mockery::mock(SourceFetchExecutorInterface::class);
        $executor->shouldReceive('fetch')
            ->once()
            ->with($spec, CacheProfile::Search->inlineBudgetSeconds())
            ->andThrow(new RuntimeException('offline'));
        $executor->shouldReceive('emptyResult')->twice()->with($spec)->andReturn($empty);
        $dispatcher = $this->prepareDispatchContainer($cache);
        $dispatcher->shouldReceive('dispatch')
            ->once()
            ->withArgs(fn (RevalidateSourceCache $job): bool => $job->uniqueId() === $spec->cacheKey())
            ->andReturnNull();
        $sourceCache = $this->sourceCache($cache, 'database', $executor);

        self::assertSame($empty, $sourceCache->swr($spec, CacheProfile::Search));
        self::assertIsArray($cache->get($spec->cacheKey().':failure:v1'));

        // The marker suppresses another inline call and another queued job.
        self::assertSame($empty, $sourceCache->swr($spec, CacheProfile::Search));
    }

    public function test_partial_fetch_returns_uncached_fallback_and_marks_failure(): void
    {
        $cache = $this->cache();
        $spec = $this->spec();
        $fallback = ['hits' => [['project_id' => 'partial']], 'total_hits' => 1];
        $executor = Mockery::mock(SourceFetchExecutorInterface::class);
        $executor->shouldReceive('fetch')
            ->once()
            ->with($spec, CacheProfile::Search->inlineBudgetSeconds())
            ->andThrow(new PartialSourceFetchException('partial', $fallback));
        $executor->shouldNotReceive('emptyResult');

        $result = $this->sourceCache($cache, 'sync', $executor)
            ->swr($spec, CacheProfile::Search);

        self::assertSame($fallback, $result);
        self::assertNull($cache->get($spec->cacheKey()));
        self::assertIsArray($cache->get($spec->cacheKey().':failure:v1'));
    }

    public function test_required_fetch_propagates_a_cold_failure_instead_of_returning_empty(): void
    {
        $cache = $this->cache();
        $spec = $this->spec();
        $executor = Mockery::mock(SourceFetchExecutorInterface::class);
        $executor->shouldReceive('fetch')
            ->once()
            ->with($spec, CacheProfile::Search->backgroundTimeoutSeconds())
            ->andThrow(new RuntimeException('offline'));
        $executor->shouldNotReceive('emptyResult');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('offline');

        $this->sourceCache($cache, 'sync', $executor)
            ->swrRequired($spec, CacheProfile::Search);
    }

    public function test_required_fetch_accepts_stale_data_without_contacting_upstream(): void
    {
        $cache = $this->cache();
        $spec = $this->spec();
        $stale = ['hits' => [['project_id' => 'stale']], 'total_hits' => 1];
        $cache->put($spec->cacheKey(), $this->entry($stale, time() - 1), 300);
        $executor = Mockery::mock(SourceFetchExecutorInterface::class);
        $executor->shouldNotReceive('fetch');
        $executor->shouldNotReceive('emptyResult');

        $result = $this->sourceCache($cache, 'sync', $executor)
            ->swrRequired($spec, CacheProfile::Search);

        self::assertSame($stale, $result);
    }

    public function test_sync_queue_refreshes_stale_data_inline_without_dispatching(): void
    {
        $cache = $this->cache();
        $spec = $this->spec();
        $cache->put($spec->cacheKey(), $this->entry(['old'], time() - 1), 300);
        $executor = Mockery::mock(SourceFetchExecutorInterface::class);
        $executor->shouldReceive('fetch')
            ->once()
            ->with($spec, CacheProfile::Search->inlineBudgetSeconds())
            ->andReturn(['new']);
        $executor->shouldNotReceive('emptyResult');

        $result = $this->sourceCache($cache, 'null', $executor)
            ->swr($spec, CacheProfile::Search);

        self::assertSame(['new'], $result);
        self::assertSame(['new'], $cache->get($spec->cacheKey())['data']);
    }

    public function test_revalidation_failure_preserves_existing_stale_entry(): void
    {
        $cache = $this->cache();
        $spec = $this->spec();
        $cache->put($spec->cacheKey(), $this->entry(['stale'], time() - 1), 300);
        $executor = Mockery::mock(SourceFetchExecutorInterface::class);
        $executor->shouldReceive('fetch')
            ->once()
            ->with($spec, CacheProfile::Search->backgroundTimeoutSeconds())
            ->andThrow(new RuntimeException('offline'));

        $this->sourceCache($cache, 'database', $executor)
            ->revalidate($spec, CacheProfile::Search);

        self::assertSame(['stale'], $cache->get($spec->cacheKey())['data']);
        self::assertIsArray($cache->get($spec->cacheKey().':failure:v1'));
    }

    public function test_cache_profiles_match_the_stage_three_policy(): void
    {
        self::assertSame(30 * 24 * 60 * 60, CacheProfile::HashMatch->freshTtlSeconds());
        self::assertNull(CacheProfile::HashMatch->staleTtlSeconds());
        self::assertSame(30 * 24 * 60 * 60, CacheProfile::VersionFile->freshTtlSeconds());
        self::assertNull(CacheProfile::VersionFile->staleTtlSeconds());
        self::assertSame(10 * 60, CacheProfile::Search->freshTtlSeconds());
        self::assertSame(24 * 60 * 60, CacheProfile::Search->staleTtlSeconds());
        self::assertSame(24 * 60 * 60, CacheProfile::ProjectMetadata->freshTtlSeconds());
        self::assertSame(7 * 24 * 60 * 60, CacheProfile::ProjectMetadata->staleTtlSeconds());
        self::assertSame(30 * 60, CacheProfile::InstalledLatest->freshTtlSeconds());
        self::assertSame(24 * 60 * 60, CacheProfile::InstalledLatest->staleTtlSeconds());
        self::assertSame(7 * 24 * 60 * 60, CacheProfile::Identity->freshTtlSeconds());
        self::assertSame(30 * 24 * 60 * 60, CacheProfile::Identity->staleTtlSeconds());
        self::assertSame(1.5, CacheProfile::Search->inlineBudgetSeconds());
        self::assertSame(30, CacheProfile::Search->failureMarkerTtlSeconds());
    }

    public function test_fetch_spec_is_canonical_and_rejects_non_serializable_arguments(): void
    {
        $left = new SourceFetchSpec('modrinth', 'search', [
            'filters' => ['sort' => 'downloads', 'category' => null],
            'page' => 1,
        ]);
        $right = new SourceFetchSpec('modrinth', 'search', [
            'page' => 1,
            'filters' => ['category' => null, 'sort' => 'downloads'],
        ]);

        self::assertSame($left->cacheKey(), $right->cacheKey());

        $this->expectException(InvalidArgumentException::class);
        new SourceFetchSpec('modrinth', 'search', ['server' => new \stdClass()]);
    }

    public function test_revalidation_job_is_unique_by_source_cache_key(): void
    {
        $spec = $this->spec();
        $job = new RevalidateSourceCache($spec, CacheProfile::Search);

        self::assertInstanceOf(ShouldBeUnique::class, $job);
        self::assertSame($spec->cacheKey(), $job->uniqueId());
        self::assertSame($job->uniqueId(), (new RevalidateSourceCache($spec, CacheProfile::Search))->uniqueId());
    }

    public function test_executor_resolves_registry_lazily_and_delegates_to_source_handler(): void
    {
        $spec = $this->spec();
        $handler = Mockery::mock(ProjectSourceInterface::class, SourceFetchHandlerInterface::class);
        $handler->shouldReceive('fetchSourceData')->once()->with($spec, 1.5)->andReturn(['ok']);
        $handler->shouldReceive('emptySourceData')->once()->with($spec)->andReturn([]);
        $registry = Mockery::mock(ProjectSourceRegistry::class);
        $registry->shouldReceive('getByValue')->twice()->with('modrinth')->andReturn($handler);
        $container = Mockery::mock(\Illuminate\Contracts\Container\Container::class);
        $container->shouldReceive('make')->twice()->with(ProjectSourceRegistry::class)->andReturn($registry);
        $executor = new SourceFetchExecutor($container);

        self::assertSame(['ok'], $executor->fetch($spec, 1.5));
        self::assertSame([], $executor->emptyResult($spec));
    }

    private function cache(): LaravelCacheRepository
    {
        return new LaravelCacheRepository(new ArrayStore());
    }

    private function sourceCache(
        CacheRepository $cache,
        string $queueDriver,
        SourceFetchExecutorInterface $executor,
    ): SourceCache {
        $config = new LaravelConfigRepository(['queue' => ['default' => $queueDriver]]);
        $dispatcher = Mockery::mock(Dispatcher::class);
        $operations = new InstalledOperationManager($cache, $config, $dispatcher);

        return new SourceCache($cache, $operations, $executor);
    }

    private function prepareDispatchContainer(CacheRepository $cache): Dispatcher
    {
        $config = new LaravelConfigRepository([
            'cache' => ['default' => 'array'],
            'queue' => ['default' => 'database'],
        ]);
        $dispatcher = Mockery::mock(Dispatcher::class);
        $context = Mockery::mock(ContextRepository::class);
        $context->shouldReceive('addHidden')->zeroOrMoreTimes();
        $context->shouldReceive('forgetHidden')->zeroOrMoreTimes();
        $container = new Container();
        $container->instance(CacheRepository::class, $cache);
        $container->instance(ConfigRepository::class, $config);
        $container->instance('config', $config);
        $container->instance(Dispatcher::class, $dispatcher);
        $container->instance(ContextRepository::class, $context);
        Container::setInstance($container);
        Facade::setFacadeApplication($container);

        return $dispatcher;
    }

    /** @return array{v: int, data: mixed, fresh_until: int} */
    private function entry(mixed $data, int $freshUntil): array
    {
        return [
            'v' => SourceCache::SCHEMA_VERSION,
            'data' => $data,
            'fresh_until' => $freshUntil,
        ];
    }

    private function spec(): SourceFetchSpec
    {
        return new SourceFetchSpec('modrinth', 'search', [
            'page' => 1,
            'query' => 'sodium',
        ]);
    }
}
