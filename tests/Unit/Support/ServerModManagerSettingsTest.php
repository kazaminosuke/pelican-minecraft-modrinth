<?php

namespace Kazaminosuke\ModManager\Tests\Unit\Support;

use App\Models\Server;
use Illuminate\Config\Repository as LaravelConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Kazaminosuke\ModManager\Enums\ProjectOperation;
use Kazaminosuke\ModManager\Models\ModManagerServerSetting;
use Kazaminosuke\ModManager\Repositories\ServerModManagerSettingRepository;
use Kazaminosuke\ModManager\Support\ServerModManagerSettings;
use PHPUnit\Framework\TestCase;

final class ServerModManagerSettingsTest extends TestCase
{
    private static ?Capsule $capsule = null;

    private ?Container $previousContainer = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousContainer = Container::getInstance();
        $container = new Container();
        $container->instance('config', new LaravelConfigRepository([
            'pelican-minecraft-modrinth' => [
                'allow_user_egg_profile_edit' => true,
                'allow_user_project_install' => true,
                'allow_user_project_update' => false,
                'allow_user_project_delete' => false,
            ],
        ]));
        Container::setInstance($container);

        if (self::$capsule === null) {
            self::$capsule = new Capsule();
            self::$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
            self::$capsule->setAsGlobal();
            self::$capsule->bootEloquent();
        }

        Capsule::schema()->dropIfExists('mod_manager_server_settings');
        Capsule::schema()->create('mod_manager_server_settings', function ($table): void {
            $table->id();
            $table->unsignedInteger('server_id')->unique();
            $table->boolean('enabled')->default(true);
            $table->boolean('allow_user_egg_profile_edit')->nullable();
            $table->boolean('allow_user_project_install')->nullable();
            $table->boolean('allow_user_project_update')->nullable();
            $table->boolean('allow_user_project_delete')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Container::setInstance($this->previousContainer);

        parent::tearDown();
    }

    public function test_missing_row_is_fully_backward_compatible(): void
    {
        $settings = $this->settings();
        $server = $this->server(1);

        self::assertTrue($settings->isEnabled($server));
        self::assertTrue($settings->allowsEggProfileEdit($server));
        self::assertTrue($settings->allowsProjectOperation($server, ProjectOperation::Install));
        self::assertFalse($settings->allowsProjectOperation($server, ProjectOperation::Update));
    }

    public function test_null_inherits_and_false_stays_false_when_global_is_true(): void
    {
        $server = $this->server(1);
        ModManagerServerSetting::query()->create([
            'server_id' => 1,
            'enabled' => true,
            'allow_user_project_install' => null,
            'allow_user_project_update' => false,
        ]);

        $settings = $this->settings();

        self::assertTrue($settings->allowsProjectOperation($server, ProjectOperation::Install));
        self::assertFalse($settings->allowsProjectOperation($server, ProjectOperation::Update));
        self::assertNull($settings->override($server, 'allow_user_project_install'));
        self::assertFalse($settings->override($server, 'allow_user_project_update'));
    }

    public function test_true_override_wins_over_global_false_and_save_updates_request_memo(): void
    {
        $server = $this->server(1);
        $repository = new ServerModManagerSettingRepository();
        $settings = new ServerModManagerSettings($repository);

        self::assertTrue($settings->isEnabled($server));
        $saved = $repository->save($server, [
            'enabled' => false,
            'allow_user_project_update' => true,
        ]);

        self::assertFalse($settings->isEnabled($server));
        self::assertTrue($settings->allowsProjectOperation($server, ProjectOperation::Update));
        self::assertSame($saved, $repository->forServer($server));

        $repository->clear();
        self::assertFalse($repository->forServer($server)->enabled);
    }

    private function settings(): ServerModManagerSettings
    {
        return new ServerModManagerSettings(new ServerModManagerSettingRepository());
    }

    private function server(int $id): Server
    {
        $server = new Server();
        $server->id = $id;

        return $server;
    }
}
