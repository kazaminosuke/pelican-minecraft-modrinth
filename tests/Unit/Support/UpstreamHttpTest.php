<?php

namespace Kazaminosuke\ModManager\Tests\Unit\Support;

use Illuminate\Container\Container;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Facade;
use Kazaminosuke\ModManager\Support\UpstreamHttp;
use PHPUnit\Framework\TestCase;
use ReflectionObject;

class UpstreamHttpTest extends TestCase
{
    private ?Container $previousContainer = null;

    private mixed $previousFacadeApplication = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousContainer = Container::getInstance();
        $this->previousFacadeApplication = Facade::getFacadeApplication();
        $container = new Container();
        $container->singleton(Factory::class, static fn (): Factory => new Factory());
        Container::setInstance($container);
        Facade::setFacadeApplication($container);
    }

    protected function tearDown(): void
    {
        Container::setInstance($this->previousContainer);
        Facade::setFacadeApplication($this->previousFacadeApplication);

        parent::tearDown();
    }

    public function test_json_client_requests_gzip_and_decodes_the_body(): void
    {
        $pending = UpstreamHttp::json(['x-api-key' => 'test-key']);
        $options = (new ReflectionObject($pending))->getProperty('options')->getValue($pending);
        $headers = $options['headers'] ?? [];
        $decodeContent = $options['decode_content'] ?? null;

        self::assertSame('gzip, deflate', $headers['Accept-Encoding'] ?? $headers['accept-encoding'] ?? null);
        self::assertSame('test-key', $headers['x-api-key'] ?? $headers['X-Api-Key'] ?? null);
        self::assertTrue($decodeContent === true || $decodeContent === 'gzip,deflate' || $decodeContent === 'gzip, deflate');
    }
}
