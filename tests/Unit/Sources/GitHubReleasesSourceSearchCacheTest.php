<?php

namespace Kazaminosuke\ModManager\Tests\Unit\Sources;

use App\Models\Server;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as LaravelCacheRepository;
use Illuminate\Config\Repository as LaravelConfigRepository;
use Illuminate\Contracts\Bus\Dispatcher;
use Kazaminosuke\ModManager\Contracts\SourceFetchExecutorInterface;
use Kazaminosuke\ModManager\Enums\ProjectType;
use Kazaminosuke\ModManager\Services\InstalledOperationManager;
use Kazaminosuke\ModManager\Sources\GitHubReleasesSource;
use Kazaminosuke\ModManager\Support\SourceCache;
use Mockery;
use PHPUnit\Framework\TestCase;

class GitHubReleasesSourceSearchCacheTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_always_reports_a_hit_since_search_never_touches_the_cache(): void
    {
        // SourceCache is a final class; a real instance (never actually
        // called - see below) stands in for one instead of a Mockery mock.
        $cache = new LaravelCacheRepository(new ArrayStore());
        $config = new LaravelConfigRepository(['queue' => ['default' => 'sync']]);
        $operations = new InstalledOperationManager($cache, $config, Mockery::mock(Dispatcher::class));
        $sourceCache = new SourceCache($cache, $operations, Mockery::mock(SourceFetchExecutorInterface::class));
        $source = new GitHubReleasesSource($sourceCache);

        // No expectations on the Server mock: search() (and therefore
        // hasCachedSearch(), which must mirror it) never reads $server at
        // all - GitHub Releases has no catalog to browse.
        $server = Mockery::mock(Server::class);

        self::assertTrue($source->hasCachedSearch($server, ProjectType::Mod, 1, null, []));
        self::assertSame(
            ['hits' => [], 'total_hits' => 0],
            $source->search($server, ProjectType::Mod, 1, null, []),
        );
    }
}
