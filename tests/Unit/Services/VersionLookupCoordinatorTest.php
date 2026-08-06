<?php

namespace Kazaminosuke\ModManager\Tests\Unit\Services;

use App\Models\Server;
use Kazaminosuke\ModManager\Contracts\BatchLatestVersionSourceInterface;
use Kazaminosuke\ModManager\Contracts\ProjectSourceInterface;
use Kazaminosuke\ModManager\Enums\ProjectType;
use Kazaminosuke\ModManager\Services\VersionLookupCoordinator;
use Kazaminosuke\ModManager\Support\LatestVersionLookupRequest;
use Kazaminosuke\ModManager\Support\LatestVersionLookupResult;
use Kazaminosuke\ModManager\Support\ProjectSourceRegistry;
use Mockery;
use PHPUnit\Framework\TestCase;

class VersionLookupCoordinatorTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_requests_are_grouped_by_source_and_results_are_merged(): void
    {
        $server = $this->server();
        $modrinthRequest = new LatestVersionLookupRequest('modrinth', 'alpha');
        $curseForgeRequest = new LatestVersionLookupRequest('curseforge', '42');
        $modrinth = $this->source();
        $curseForge = $this->source();

        $modrinth->shouldReceive('isConfigured')->once()->andReturnTrue();
        $modrinth->shouldReceive('lookupLatestVersions')
            ->once()
            ->with([$modrinthRequest], $server, ProjectType::Mod)
            ->andReturn(new LatestVersionLookupResult([
                $modrinthRequest->key() => ['id' => 'new-alpha'],
            ]));

        $curseForge->shouldReceive('isConfigured')->once()->andReturnTrue();
        $curseForge->shouldReceive('lookupLatestVersions')
            ->once()
            ->with([$curseForgeRequest], $server, ProjectType::Mod)
            ->andReturn(new LatestVersionLookupResult(
                unresolvedKeys: [$curseForgeRequest->key()],
            ));

        $registry = Mockery::mock(ProjectSourceRegistry::class);
        $registry->shouldReceive('getByValue')->once()->with('modrinth')->andReturn($modrinth);
        $registry->shouldReceive('getByValue')->once()->with('curseforge')->andReturn($curseForge);

        $result = (new VersionLookupCoordinator($registry))->lookup(
            [$modrinthRequest, $curseForgeRequest],
            $server,
            ProjectType::Mod,
        );

        self::assertSame('new-alpha', $result->version($modrinthRequest->key())['id']);
        self::assertSame([$curseForgeRequest->key()], $result->unresolvedKeys());
        self::assertSame([], $result->failures());
    }

    public function test_unavailable_source_only_fails_its_own_requests(): void
    {
        $server = $this->server();
        $availableRequest = new LatestVersionLookupRequest('modrinth', 'alpha');
        $missingRequest = new LatestVersionLookupRequest('hangar', 'missing');
        $source = $this->source();
        $source->shouldReceive('isConfigured')->once()->andReturnTrue();
        $source->shouldReceive('lookupLatestVersions')->once()->andReturn(new LatestVersionLookupResult([
            $availableRequest->key() => ['id' => 'latest'],
        ]));

        $registry = Mockery::mock(ProjectSourceRegistry::class);
        $registry->shouldReceive('getByValue')->once()->with('modrinth')->andReturn($source);
        $registry->shouldReceive('getByValue')->once()->with('hangar')->andReturnNull();

        $result = (new VersionLookupCoordinator($registry))->lookup(
            [$availableRequest, $missingRequest],
            $server,
            ProjectType::Mod,
        );

        self::assertSame('latest', $result->version($availableRequest->key())['id']);
        self::assertArrayHasKey($missingRequest->key(), $result->failures());
        self::assertArrayNotHasKey($availableRequest->key(), $result->failures());
    }

    protected function source(): ProjectSourceInterface&BatchLatestVersionSourceInterface
    {
        return Mockery::mock(ProjectSourceInterface::class, BatchLatestVersionSourceInterface::class);
    }

    protected function server(): Server
    {
        $server = new Server();
        $server->forceFill(['id' => 42]);

        return $server;
    }
}
