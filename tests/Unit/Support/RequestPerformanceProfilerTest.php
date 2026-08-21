<?php

namespace Kazaminosuke\ModManager\Tests\Unit\Support;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as LaravelCacheRepository;
use Illuminate\Config\Repository as LaravelConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use Kazaminosuke\ModManager\Contracts\SourceFetchExecutorInterface;
use Kazaminosuke\ModManager\Services\InstalledOperationManager;
use Kazaminosuke\ModManager\Support\CacheProfile;
use Kazaminosuke\ModManager\Support\RequestPerformanceProfiler;
use Kazaminosuke\ModManager\Support\SourceCache;
use Kazaminosuke\ModManager\Support\SourceFetchSpec;
use Mockery;
use PHPUnit\Framework\TestCase;

class RequestPerformanceProfilerTest extends TestCase
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

    public function test_is_not_capturing_until_start(): void
    {
        $this->bindRequest(Request::create('/mod-manager', 'GET'));

        self::assertFalse(RequestPerformanceProfiler::isCapturing());
        self::assertSame('NONE', RequestPerformanceProfiler::snapshot()['cache']);
    }

    public function test_fresh_search_hit_is_recorded_without_api_time(): void
    {
        $this->bindRequest(Request::create('/mod-manager', 'GET'));
        RequestPerformanceProfiler::start('abc123');

        $spec = new SourceFetchSpec('curseforge', 'search', ['page' => 1]);
        $data = ['hits' => [['project_id' => 'one']], 'total_hits' => 1];
        $cache = new LaravelCacheRepository(new ArrayStore());
        $cache->put($spec->cacheKey(), [
            'v' => SourceCache::SCHEMA_VERSION,
            'data' => $data,
            'fresh_until' => time() + 60,
        ], 300);

        $executor = Mockery::mock(SourceFetchExecutorInterface::class);
        $executor->shouldNotReceive('fetch');

        $result = $this->sourceCache($cache, $executor)->swr($spec, CacheProfile::Search);
        $snapshot = RequestPerformanceProfiler::snapshot();

        self::assertSame($data, $result);
        self::assertTrue(RequestPerformanceProfiler::isCapturing());
        self::assertSame('abc123', $snapshot['request_id']);
        self::assertSame('HIT', $snapshot['cache']);
        self::assertSame('curseforge', $snapshot['source']);
        self::assertSame(0, $snapshot['api_ms']);
    }

    public function test_search_miss_records_upstream_api_time(): void
    {
        $this->bindRequest(Request::create('/mod-manager', 'GET'));
        RequestPerformanceProfiler::start('miss1');

        $spec = new SourceFetchSpec('modrinth', 'search', ['page' => 42]);
        $data = ['hits' => [], 'total_hits' => 0];
        $cache = new LaravelCacheRepository(new ArrayStore());
        $executor = Mockery::mock(SourceFetchExecutorInterface::class);
        $executor->shouldReceive('fetch')
            ->once()
            ->andReturnUsing(function () use ($data) {
                RequestPerformanceProfiler::addSearchApi('modrinth', 17);

                return $data;
            });

        $result = $this->sourceCache($cache, $executor)->swr($spec, CacheProfile::Search);
        $snapshot = RequestPerformanceProfiler::snapshot();

        self::assertSame($data, $result);
        self::assertSame('MISS', $snapshot['cache']);
        self::assertSame('modrinth', $snapshot['source']);
        self::assertSame(17, $snapshot['api_ms']);
    }

    private function bindRequest(Request $request): void
    {
        $container = new Container();
        $container->instance('request', $request);
        Container::setInstance($container);
        Facade::setFacadeApplication($container);
    }

    private function sourceCache(CacheRepository $cache, SourceFetchExecutorInterface $executor): SourceCache
    {
        $config = new LaravelConfigRepository(['queue' => ['default' => 'sync']]);
        $operations = new InstalledOperationManager($cache, $config);

        return new SourceCache($cache, $operations, $executor);
    }
}
