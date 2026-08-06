<?php

namespace Kazaminosuke\ModManager\Tests\Unit\Support;

use App\Models\Server;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kazaminosuke\ModManager\Support\MinecraftVersionResolver;
use Mockery;
use PHPUnit\Framework\TestCase;

class MinecraftVersionResolverTest extends TestCase
{
    protected function tearDown(): void
    {
        MinecraftVersionResolver::clear();
        Mockery::close();

        parent::tearDown();
    }

    public function test_version_is_memoized_per_server_until_runtime_cache_is_cleared(): void
    {
        $variables = Mockery::mock(HasMany::class);
        $variables->shouldReceive('where')
            ->twice()
            ->with(Mockery::type(\Closure::class))
            ->andReturnSelf();
        $variables->shouldReceive('first')
            ->twice()
            ->andReturn((object) ['server_value' => '1.21.8']);

        $server = Mockery::mock(Server::class);
        $server->shouldReceive('getKey')->times(3)->andReturn(42);
        $server->shouldReceive('variables')->twice()->andReturn($variables);

        self::assertSame('1.21.8', MinecraftVersionResolver::resolve($server));
        self::assertSame('1.21.8', MinecraftVersionResolver::resolve($server));

        MinecraftVersionResolver::clear();

        self::assertSame('1.21.8', MinecraftVersionResolver::resolve($server));
    }
}
