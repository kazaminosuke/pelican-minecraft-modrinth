<?php

namespace Kazaminosuke\ModManager\Tests\Unit\Support;

use App\Models\Server;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as LaravelCacheRepository;
use Illuminate\Container\Container;
use Kazaminosuke\ModManager\Support\CacheVersion;
use PHPUnit\Framework\TestCase;

class CacheVersionTest extends TestCase
{
    private ?Container $previousContainer = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousContainer = Container::getInstance();
        $container = new Container();
        $container->instance('cache', new LaravelCacheRepository(new ArrayStore()));
        Container::setInstance($container);
    }

    protected function tearDown(): void
    {
        Container::setInstance($this->previousContainer);

        parent::tearDown();
    }

    public function test_generations_increment_for_multiple_writes_in_the_same_second(): void
    {
        $server = new Server();
        $server->forceFill(['id' => 42]);

        CacheVersion::bumpHydration($server);
        CacheVersion::bumpHydration($server);
        CacheVersion::bumpHangarHash();
        CacheVersion::bumpHangarHash();

        self::assertSame(2, CacheVersion::hydration($server));
        self::assertSame(2, CacheVersion::hangarHash());
    }
}
