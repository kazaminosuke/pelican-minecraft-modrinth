<?php

namespace Kazaminosuke\ModManager\Tests\Unit\Support;

use App\Models\Egg;
use App\Models\Server;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as LaravelCacheRepository;
use Illuminate\Config\Repository as LaravelConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Log\Context\Repository as ContextRepository;
use Illuminate\Support\Facades\Facade;
use Kazaminosuke\ModManager\Contracts\ProjectSourceInterface;
use Kazaminosuke\ModManager\Enums\ProjectType;
use Kazaminosuke\ModManager\Jobs\WarmProjectMetadata;
use Kazaminosuke\ModManager\Services\InstalledOperationManager;
use Kazaminosuke\ModManager\Sources\CurseForgeSource;
use Kazaminosuke\ModManager\Sources\GitHubReleasesSource;
use Kazaminosuke\ModManager\Sources\HangarSource;
use Kazaminosuke\ModManager\Sources\ModrinthSource;
use Kazaminosuke\ModManager\Support\ProjectSourceRegistry;
use Kazaminosuke\ModManager\Support\EggProfileRegistry;
use Kazaminosuke\ModManager\Support\EggProfileResolver;
use Mockery;
use PHPUnit\Framework\TestCase;

class ProjectSourceRegistryTest extends TestCase
{
    private ?Container $previousContainer = null;

    private mixed $previousFacadeApplication = null;

    private static ?Capsule $capsule = null;

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
        $container->instance('config', new LaravelConfigRepository([
            'pelican-minecraft-modrinth' => ['egg_autodetect_enabled' => true],
        ]));
        Container::setInstance($container);
        Facade::setFacadeApplication($container);

        if (self::$capsule === null) {
            self::$capsule = new Capsule();
            self::$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
            self::$capsule->setAsGlobal();
            self::$capsule->bootEloquent();
        }
    }

    protected function tearDown(): void
    {
        Container::setInstance($this->previousContainer);
        Facade::setFacadeApplication($this->previousFacadeApplication);
        EggProfileRegistry::clear();
        EggProfileResolver::clear();
        Mockery::close();

        parent::tearDown();
    }

    public function test_peek_installed_uses_cached_project_data_without_fetching(): void
    {
        $modrinth = Mockery::mock(ModrinthSource::class);
        $modrinth->shouldReceive('peekProject')
            ->once()
            ->with('abc', false)
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
            ->with('abc', false)
            ->andReturn(['data' => null, 'pending' => true]);

        // Async dispatch unsupported here on purpose: this test is only
        // about the placeholder row's shape, not about dispatch - see
        // test_peek_installed_dispatches_one_batched_job_per_source_for_all_misses
        // for that.
        $registry = $this->registryWith(modrinth: $modrinth, supportsAsyncDispatch: false);

        $rows = $registry->peekInstalled([
            ['source' => 'modrinth', 'project_id' => 'abc', 'project_title' => 'Sodium', 'project_slug' => 'sodium'],
        ], $this->server());

        self::assertCount(1, $rows);
        self::assertTrue($rows[0]['enrichment_pending']);
        self::assertSame('Sodium', $rows[0]['title']);
        self::assertSame('sodium', $rows[0]['slug']);
        self::assertNull($rows[0]['icon_url']);
    }

    public function test_peek_installed_returns_an_unavailable_placeholder_for_an_unrecognized_source(): void
    {
        $registry = $this->registryWith(supportsAsyncDispatch: false);

        $rows = $registry->peekInstalled([
            ['source' => 'not_a_real_source', 'project_id' => 'gone', 'project_title' => 'Removed Mod'],
        ], $this->server());

        self::assertCount(1, $rows);
        self::assertTrue($rows[0]['unavailable']);
        self::assertArrayNotHasKey('enrichment_pending', $rows[0]);
    }

    public function test_peek_installed_dispatches_one_batched_job_per_source_for_all_misses(): void
    {
        $modrinth = Mockery::mock(ModrinthSource::class);
        $modrinth->shouldReceive('peekProject')
            ->once()->with('a', false)->andReturn(['data' => null, 'pending' => true]);
        $modrinth->shouldReceive('peekProject')
            ->once()->with('b', false)->andReturn(['data' => null, 'pending' => true]);

        $registry = $this->registryWith(modrinth: $modrinth, supportsAsyncDispatch: true);
        $dispatcher = $this->bindDispatcher();
        $dispatcher->shouldReceive('dispatch')
            ->once()
            ->withArgs(fn (WarmProjectMetadata $job): bool => $job->sourceKey === 'modrinth'
                && $job->projectIds === ['a', 'b'])
            ->andReturnNull();

        $rows = $registry->peekInstalled([
            ['source' => 'modrinth', 'project_id' => 'a', 'project_title' => 'A'],
            ['source' => 'modrinth', 'project_id' => 'b', 'project_title' => 'B'],
        ], $this->server());

        self::assertTrue($rows[0]['enrichment_pending']);
        self::assertTrue($rows[1]['enrichment_pending']);
    }

    public function test_peek_installed_does_not_dispatch_when_async_dispatch_is_unsupported(): void
    {
        $modrinth = Mockery::mock(ModrinthSource::class);
        $modrinth->shouldReceive('peekProject')
            ->once()->with('a', false)->andReturn(['data' => null, 'pending' => true]);

        $registry = $this->registryWith(modrinth: $modrinth, supportsAsyncDispatch: false);

        // No dispatcher bound at all: this only passes if peekInstalled()
        // never attempts a dispatch when async isn't supported, since any
        // attempt would throw resolving an unbound Dispatcher.
        $rows = $registry->peekInstalled([
            ['source' => 'modrinth', 'project_id' => 'a', 'project_title' => 'A'],
        ], $this->server());

        self::assertTrue($rows[0]['enrichment_pending']);
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

    public function test_paper_profile_enables_curseforge_by_default_for_plugin_catalogs(): void
    {
        EggProfileRegistry::seed([[
            'id' => 'paper',
            'match' => ['uuid' => ['paper-uuid'], 'name_aliases' => ['paper'], 'variable_signatures' => []],
            'status' => 'resolved',
            'project_type' => 'plugin',
            'loader' => 'paper',
            'is_proxy' => false,
            'minecraft_version_variables' => ['MINECRAFT_VERSION'],
        ]]);
        $server = $this->serverWithEgg('paper-uuid');
        $modrinth = Mockery::mock(ModrinthSource::class);
        $curseForge = Mockery::mock(CurseForgeSource::class);
        $modrinth->shouldReceive('supportsProjectType')->once()->with(ProjectType::Plugin)->andReturnTrue();
        $curseForge->shouldReceive('supportsProjectType')->once()->with(ProjectType::Plugin)->andReturnTrue();

        self::assertSame(ProjectType::Plugin, ProjectType::fromServer($server));
        self::assertSame(
            [$curseForge, $modrinth],
            $this->registryWith(modrinth: $modrinth, curseForge: $curseForge)->availableFor($server, ProjectType::Plugin),
        );
    }

    public function test_curseforge_disabled_feature_overrides_the_plugin_default(): void
    {
        $server = $this->serverWithEgg(features: ['curseforge_disabled']);
        $modrinth = Mockery::mock(ModrinthSource::class);
        $modrinth->shouldReceive('supportsProjectType')->once()->with(ProjectType::Plugin)->andReturnTrue();

        self::assertSame(
            [$modrinth],
            $this->registryWith(modrinth: $modrinth)->availableFor($server, ProjectType::Plugin),
        );
    }

    protected function registryWith(
        ?ProjectSourceInterface $modrinth = null,
        ?CurseForgeSource $curseForge = null,
        bool $supportsAsyncDispatch = false,
    ): ProjectSourceRegistry {
        // InstalledOperationManager is final, so it can't be Mockery-mocked
        // directly - construct a real instance and drive
        // supportsAsyncDispatch() through its queue.default config, same
        // as SourceCacheTest's sourceCache() helper.
        $config = new LaravelConfigRepository([
            'queue' => ['default' => $supportsAsyncDispatch ? 'database' : 'sync'],
        ]);
        $operations = new InstalledOperationManager(
            new LaravelCacheRepository(new ArrayStore()),
            $config,
            Mockery::mock(Dispatcher::class),
        );

        return new ProjectSourceRegistry(
            $modrinth ?? Mockery::mock(ModrinthSource::class),
            $curseForge ?? Mockery::mock(CurseForgeSource::class),
            Mockery::mock(HangarSource::class),
            Mockery::mock(GitHubReleasesSource::class),
            $operations,
        );
    }

    /**
     * Binds a mocked Dispatcher into a fresh container so
     * WarmProjectMetadata::dispatch() resolves it instead of a real queue
     * connection - mirrors SourceCacheTest's prepareDispatchContainer().
     */
    protected function bindDispatcher(): Dispatcher
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
        $container->instance(CacheRepository::class, new LaravelCacheRepository(new ArrayStore()));
        $container->instance(ConfigRepository::class, $config);
        $container->instance('config', $config);
        $container->instance(Dispatcher::class, $dispatcher);
        $container->instance(ContextRepository::class, $context);
        Container::setInstance($container);
        Facade::setFacadeApplication($container);

        return $dispatcher;
    }

    protected function server(): Server
    {
        $server = new Server();
        $server->forceFill(['id' => 7]);

        return $server;
    }

    private function serverWithEgg(?string $uuid = null, array $features = []): Server
    {
        $egg = new Egg();
        $egg->forceFill(['id' => 70, 'uuid' => $uuid, 'name' => 'Paper', 'features' => $features, 'tags' => ['minecraft']]);
        $egg->setRelation('variables', collect());

        $server = new Server();
        $server->forceFill(['id' => 70]);
        $server->setRelation('egg', $egg);

        return $server;
    }
}
