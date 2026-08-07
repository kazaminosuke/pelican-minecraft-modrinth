<?php

namespace Kazaminosuke\ModManager\Tests\Unit\Sources;

use App\Models\Server;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as LaravelCacheRepository;
use Illuminate\Config\Repository as LaravelConfigRepository;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kazaminosuke\ModManager\Contracts\SourceFetchExecutorInterface;
use Kazaminosuke\ModManager\Enums\ProjectType;
use Kazaminosuke\ModManager\Services\InstalledOperationManager;
use Kazaminosuke\ModManager\Sources\ModrinthSource;
use Kazaminosuke\ModManager\Support\MinecraftVersionResolver;
use Kazaminosuke\ModManager\Support\SourceCache;
use Mockery;
use PHPUnit\Framework\TestCase;

/**
 * hasCachedSearch() exists so ModManagerPage::hasWarmRecordsCache() (Stage 6)
 * can decide whether to skip the deferred round trip for the catalog tab
 * without ever performing an inline fetch. Its correctness depends entirely
 * on building the exact same SourceFetchSpec search() does - both methods
 * delegate to the same private buildSearchSpec() helper, so these tests
 * exercise that indirectly by asserting a cache entry search() writes is
 * the one hasCachedSearch() then reports as a hit.
 */
class ModrinthSourceSearchCacheTest extends TestCase
{
    protected function tearDown(): void
    {
        MinecraftVersionResolver::clear();
        Mockery::close();

        parent::tearDown();
    }

    public function test_returns_false_before_anything_has_populated_the_cache(): void
    {
        $source = new ModrinthSource($this->sourceCache($this->cache()));

        self::assertFalse($source->hasCachedSearch($this->server(), ProjectType::Datapack, 1, null, []));
    }

    public function test_a_cache_entry_written_by_search_is_detected_as_a_hit(): void
    {
        $cache = $this->cache();
        $executor = Mockery::mock(SourceFetchExecutorInterface::class);
        $executor->shouldReceive('fetch')->once()->andReturn(['hits' => [], 'total_hits' => 0]);
        $source = new ModrinthSource($this->sourceCache($cache, $executor));
        $server = $this->server();

        self::assertFalse($source->hasCachedSearch($server, ProjectType::Datapack, 1, null, []));

        $source->search($server, ProjectType::Datapack, 1, null, []);

        self::assertTrue($source->hasCachedSearch($server, ProjectType::Datapack, 1, null, []));
    }

    public function test_a_different_page_is_a_distinct_cache_entry(): void
    {
        $cache = $this->cache();
        $executor = Mockery::mock(SourceFetchExecutorInterface::class);
        $executor->shouldReceive('fetch')->once()->andReturn(['hits' => [], 'total_hits' => 0]);
        $source = new ModrinthSource($this->sourceCache($cache, $executor));
        $server = $this->server();

        $source->search($server, ProjectType::Datapack, 1, null, []);

        self::assertTrue($source->hasCachedSearch($server, ProjectType::Datapack, 1, null, []));
        self::assertFalse($source->hasCachedSearch($server, ProjectType::Datapack, 2, null, []));
    }

    private function cache(): LaravelCacheRepository
    {
        return new LaravelCacheRepository(new ArrayStore());
    }

    private function sourceCache(CacheRepository $cache, ?SourceFetchExecutorInterface $executor = null): SourceCache
    {
        $config = new LaravelConfigRepository(['queue' => ['default' => 'sync']]);
        $dispatcher = Mockery::mock(Dispatcher::class);
        $operations = new InstalledOperationManager($cache, $config, $dispatcher);

        return new SourceCache($cache, $operations, $executor ?? Mockery::mock(SourceFetchExecutorInterface::class));
    }

    private function server(): Server
    {
        $variables = Mockery::mock(HasMany::class);
        $variables->shouldReceive('where')->andReturnSelf();
        $variables->shouldReceive('first')->andReturn((object) ['server_value' => '1.21.1']);

        $server = Mockery::mock(Server::class);
        $server->shouldReceive('getKey')->andReturn(1);
        $server->shouldReceive('variables')->andReturn($variables);

        return $server;
    }
}
