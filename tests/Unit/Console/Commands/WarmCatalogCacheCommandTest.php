<?php

namespace Kazaminosuke\ModManager\Tests\Unit\Console\Commands;

use Illuminate\Config\Repository as LaravelConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Support\Facades\Facade;
use Kazaminosuke\ModManager\Console\Commands\WarmCatalogCacheCommand;
use Kazaminosuke\ModManager\Support\EggProfileRegistry;
use Kazaminosuke\ModManager\Support\EggProfileResolver;
use PHPUnit\Framework\TestCase;

class WarmCatalogCacheCommandTest extends TestCase
{
    private static ?Capsule $capsule = null;

    private ?Container $previousContainer = null;

    private mixed $previousFacadeApplication = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousContainer = Container::getInstance();
        $this->previousFacadeApplication = Facade::getFacadeApplication();
        $container = new Container();
        $container->instance('config', new LaravelConfigRepository([
            'pelican-minecraft-modrinth' => [
                'egg_autodetect_enabled' => true,
                'latest_minecraft_version' => '26.1.2',
            ],
        ]));
        Container::setInstance($container);

        if (self::$capsule === null) {
            self::$capsule = new Capsule();
            self::$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
            self::$capsule->setAsGlobal();
            self::$capsule->bootEloquent();
        }

        $container->instance('db', self::$capsule->getDatabaseManager());
        Facade::setFacadeApplication($container);

        Capsule::schema()->dropIfExists('server_variables');
        Capsule::schema()->dropIfExists('egg_variables');
        Capsule::schema()->dropIfExists('servers');
        Capsule::schema()->dropIfExists('eggs');
        Capsule::schema()->create('eggs', function ($table): void {
            $table->id();
            $table->string('uuid')->nullable();
            $table->string('name')->nullable();
            $table->string('update_url')->nullable();
            $table->text('features')->nullable();
            $table->text('tags')->nullable();
        });
        Capsule::schema()->create('servers', function ($table): void {
            $table->id();
            $table->unsignedBigInteger('egg_id');
        });
        Capsule::schema()->create('egg_variables', function ($table): void {
            $table->id();
            $table->unsignedBigInteger('egg_id');
            $table->string('env_variable');
        });
        Capsule::schema()->create('server_variables', function ($table): void {
            $table->id();
            $table->unsignedBigInteger('server_id');
            $table->unsignedBigInteger('variable_id');
            $table->string('variable_value')->nullable();
        });

        EggProfileRegistry::seed([
            [
                'id' => 'spigot',
                'match' => [
                    'uuid' => ['spigot-uuid'],
                    'name_aliases' => ['spigot'],
                    'variable_signatures' => [['DL_PATH', 'DL_VERSION', 'SERVER_JARFILE']],
                ],
                'status' => 'resolved',
                'project_type' => 'plugin',
                'loader' => 'spigot',
                'is_proxy' => false,
                'minecraft_version_variables' => ['DL_VERSION'],
            ],
        ]);
    }

    protected function tearDown(): void
    {
        Container::setInstance($this->previousContainer);
        Facade::setFacadeApplication($this->previousFacadeApplication);
        EggProfileRegistry::clear();
        EggProfileResolver::clear();

        parent::tearDown();
    }

    public function test_discovers_an_auto_detected_egg_and_its_profile_specific_minecraft_version(): void
    {
        Capsule::table('eggs')->insert([
            'id' => 1,
            'uuid' => 'spigot-uuid',
            'name' => 'Spigot',
            'features' => json_encode([]),
            'tags' => json_encode(['minecraft']),
        ]);
        Capsule::table('servers')->insert(['id' => 1, 'egg_id' => 1]);
        Capsule::table('egg_variables')->insert([
            ['id' => 1, 'egg_id' => 1, 'env_variable' => 'DL_PATH'],
            ['id' => 2, 'egg_id' => 1, 'env_variable' => 'DL_VERSION'],
            ['id' => 3, 'egg_id' => 1, 'env_variable' => 'SERVER_JARFILE'],
        ]);
        Capsule::table('server_variables')->insert([
            'server_id' => 1,
            'variable_id' => 2,
            'variable_value' => '1.20.4',
        ]);

        $method = new \ReflectionMethod(WarmCatalogCacheCommand::class, 'discoverCombos');
        $combos = $method->invoke(new WarmCatalogCacheCommand());

        self::assertSame([[
            'loader' => 'spigot',
            'mc_version' => '1.20.4',
            'project_type' => 'plugin',
            'server_id' => 1,
            'server_count' => 1,
        ]], $combos);
    }
}
