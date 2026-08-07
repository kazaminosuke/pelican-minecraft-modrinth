<?php

namespace Kazaminosuke\ModManager\Tests\Unit\Sources;

use App\Models\Server;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as LaravelCacheRepository;
use Illuminate\Config\Repository as LaravelConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kazaminosuke\ModManager\Contracts\SourceFetchExecutorInterface;
use Kazaminosuke\ModManager\Enums\ProjectType;
use Kazaminosuke\ModManager\Services\InstalledOperationManager;
use Kazaminosuke\ModManager\Sources\CurseForgeSource;
use Kazaminosuke\ModManager\Support\MinecraftVersionResolver;
use Kazaminosuke\ModManager\Support\SourceCache;
use Mockery;
use PHPUnit\Framework\TestCase;

class CurseForgeSourceSearchCacheTest extends TestCase
{
    private ?Container $previousContainer = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousContainer = Container::getInstance();
    }

    protected function tearDown(): void
    {
        Container::setInstance($this->previousContainer);
        MinecraftVersionResolver::clear();
        Mockery::close();

        parent::tearDown();
    }

    public function test_returns_true_without_touching_the_cache_when_unconfigured(): void
    {
        $this->bindApiKey(null);
        $source = new CurseForgeSource($this->sourceCache($this->cache()));

        // No expectations are set on this Server mock: isConfigured() must
        // short-circuit before the source ever reads anything from it.
        $server = Mockery::mock(Server::class);

        self::assertTrue($source->hasCachedSearch($server, ProjectType::Plugin, 1, null, []));
    }

    public function test_a_cache_entry_written_by_search_is_detected_as_a_hit(): void
    {
        $this->bindApiKey('test-key');
        $cache = $this->cache();
        $executor = Mockery::mock(SourceFetchExecutorInterface::class);
        $executor->shouldReceive('fetch')->once()->andReturn(['hits' => [], 'total_hits' => 0]);
        $source = new CurseForgeSource($this->sourceCache($cache, $executor));
        $server = $this->server();

        self::assertFalse($source->hasCachedSearch($server, ProjectType::Plugin, 1, null, []));

        $source->search($server, ProjectType::Plugin, 1, null, []);

        self::assertTrue($source->hasCachedSearch($server, ProjectType::Plugin, 1, null, []));
    }

    private function bindApiKey(?string $key): void
    {
        $container = new Container();
        $container->instance('config', new LaravelConfigRepository([
            'pelican-minecraft-modrinth' => ['curseforge_api_key' => $key],
        ]));
        Container::setInstance($container);
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
