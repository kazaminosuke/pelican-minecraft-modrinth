<?php

namespace Kazaminosuke\ModManager\Tests\Unit\Support;

use App\Models\Server;
use Illuminate\Container\Container;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Support\Facades\Facade;
use Kazaminosuke\ModManager\Contracts\ProjectSourceInterface;
use Kazaminosuke\ModManager\Sources\CurseForgeSource;
use Kazaminosuke\ModManager\Sources\GitHubReleasesSource;
use Kazaminosuke\ModManager\Sources\HangarSource;
use Kazaminosuke\ModManager\Sources\ModrinthSource;
use Kazaminosuke\ModManager\Support\ProjectSourceRegistry;
use Mockery;
use PHPUnit\Framework\TestCase;

class ProjectSourceRegistryTest extends TestCase
{
    private ?Container $previousContainer = null;

    private mixed $previousFacadeApplication = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousContainer = Container::getInstance();
        $this->previousFacadeApplication = Facade::getFacadeApplication();

        // unavailableEntry() calls trans() for its placeholder description;
        // stub just enough of the container for that to resolve.
        $translator = Mockery::mock(Translator::class);
        $translator->shouldReceive('get')->andReturnUsing(fn (string $key) => $key);
        $container = new Container();
        $container->instance('translator', $translator);
        Container::setInstance($container);
        Facade::setFacadeApplication($container);
    }

    protected function tearDown(): void
    {
        Container::setInstance($this->previousContainer);
        Facade::setFacadeApplication($this->previousFacadeApplication);
        Mockery::close();

        parent::tearDown();
    }

    public function test_peek_installed_uses_cached_project_data_without_fetching(): void
    {
        $modrinth = Mockery::mock(ModrinthSource::class);
        $modrinth->shouldReceive('peekProject')
            ->once()
            ->with('abc')
            ->andReturn(['data' => ['title' => 'Sodium', 'icon_url' => 'https://example.test/icon.png'], 'pending' => false]);

        $registry = $this->registryWith(modrinth: $modrinth);

        $rows = $registry->peekInstalled([
            ['source' => 'modrinth', 'project_id' => 'abc', 'project_title' => 'Sodium (stored)'],
        ], $this->server());

        self::assertCount(1, $rows);
        self::assertSame('Sodium', $rows[0]['title']);
        self::assertSame('abc', $rows[0]['project_id']);
        self::assertSame('modrinth', $rows[0]['source']);
        self::assertArrayNotHasKey('enrichment_pending', $rows[0]);
    }

    public function test_peek_installed_returns_a_pending_placeholder_on_a_cache_miss(): void
    {
        $modrinth = Mockery::mock(ModrinthSource::class);
        $modrinth->shouldReceive('peekProject')
            ->once()
            ->with('abc')
            ->andReturn(['data' => null, 'pending' => true]);

        $registry = $this->registryWith(modrinth: $modrinth);

        $rows = $registry->peekInstalled([
            ['source' => 'modrinth', 'project_id' => 'abc', 'project_title' => 'Sodium', 'project_slug' => 'sodium'],
        ], $this->server());

        self::assertCount(1, $rows);
        self::assertTrue($rows[0]['enrichment_pending']);
        self::assertSame('Sodium', $rows[0]['title']);
        self::assertSame('sodium', $rows[0]['slug']);
        self::assertNull($rows[0]['icon_url']);
    }

    public function test_peek_installed_returns_an_unavailable_placeholder_when_not_pending_and_no_data(): void
    {
        $modrinth = Mockery::mock(ModrinthSource::class);
        $modrinth->shouldReceive('peekProject')
            ->once()
            ->with('gone')
            ->andReturn(['data' => null, 'pending' => false]);

        $registry = $this->registryWith(modrinth: $modrinth);

        $rows = $registry->peekInstalled([
            ['source' => 'modrinth', 'project_id' => 'gone', 'project_title' => 'Removed Mod'],
        ], $this->server());

        self::assertCount(1, $rows);
        self::assertTrue($rows[0]['unavailable']);
        self::assertArrayNotHasKey('enrichment_pending', $rows[0]);
    }

    public function test_fetch_projects_map_uses_one_cache_entry_per_project_instead_of_a_shared_chunk(): void
    {
        $modrinth = Mockery::mock(ModrinthSource::class);
        // One getProject() call per distinct id (not one call for a
        // combined chunk) is exactly what gives each project its own,
        // independently-invalidated cache entry.
        $modrinth->shouldReceive('getProject')->once()->with('a')->andReturn(['title' => 'A']);
        $modrinth->shouldReceive('getProject')->once()->with('b')->andReturn(['title' => 'B']);

        $registry = $this->registryWith(modrinth: $modrinth);

        $rows = $registry->hydrateInstalled([
            ['source' => 'modrinth', 'project_id' => 'a', 'project_title' => 'A (stored)'],
            ['source' => 'modrinth', 'project_id' => 'b', 'project_title' => 'B (stored)'],
        ], $this->server());

        self::assertCount(2, $rows);
        self::assertSame('A', $rows[0]['title']);
        self::assertSame('B', $rows[1]['title']);
    }

    protected function registryWith(?ProjectSourceInterface $modrinth = null): ProjectSourceRegistry
    {
        return new ProjectSourceRegistry(
            $modrinth ?? Mockery::mock(ModrinthSource::class),
            Mockery::mock(CurseForgeSource::class),
            Mockery::mock(HangarSource::class),
            Mockery::mock(GitHubReleasesSource::class),
        );
    }

    protected function server(): Server
    {
        $server = new Server();
        $server->forceFill(['id' => 7]);

        return $server;
    }
}
