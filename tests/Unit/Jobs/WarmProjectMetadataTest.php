<?php

namespace Kazaminosuke\ModManager\Tests\Unit\Jobs;

use Kazaminosuke\ModManager\Jobs\WarmProjectMetadata;
use Kazaminosuke\ModManager\Sources\ModrinthSource;
use Kazaminosuke\ModManager\Support\ProjectSourceRegistry;
use Mockery;
use PHPUnit\Framework\TestCase;

class WarmProjectMetadataTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_unique_id_is_stable_regardless_of_input_order(): void
    {
        $first = new WarmProjectMetadata('modrinth', ['b', 'a', 'c']);
        $second = new WarmProjectMetadata('modrinth', ['c', 'b', 'a']);

        self::assertSame($first->uniqueId(), $second->uniqueId());
    }

    public function test_unique_id_differs_by_source_and_by_id_set(): void
    {
        $base = new WarmProjectMetadata('modrinth', ['a', 'b']);

        self::assertNotSame($base->uniqueId(), (new WarmProjectMetadata('curseforge', ['a', 'b']))->uniqueId());
        self::assertNotSame($base->uniqueId(), (new WarmProjectMetadata('modrinth', ['a', 'c']))->uniqueId());
    }

    public function test_handle_fetches_in_bulk_and_primes_every_returned_project(): void
    {
        $modrinth = Mockery::mock(ModrinthSource::class);
        $modrinth->shouldReceive('getProjectsByIds')
            ->once()
            ->with(['a', 'b'])
            ->andReturn(['a' => ['title' => 'A'], 'b' => ['title' => 'B']]);
        $modrinth->shouldReceive('primeProjects')
            ->once()
            ->with(['a' => ['title' => 'A'], 'b' => ['title' => 'B']]);

        $registry = Mockery::mock(ProjectSourceRegistry::class);
        $registry->shouldReceive('getByValue')->once()->with('modrinth')->andReturn($modrinth);

        (new WarmProjectMetadata('modrinth', ['a', 'b']))->handle($registry);

        self::assertTrue(true);
    }

    public function test_handle_does_not_prime_when_the_bulk_fetch_returns_nothing(): void
    {
        $modrinth = Mockery::mock(ModrinthSource::class);
        $modrinth->shouldReceive('getProjectsByIds')->once()->with(['a'])->andReturn([]);
        $modrinth->shouldNotReceive('primeProjects');

        $registry = Mockery::mock(ProjectSourceRegistry::class);
        $registry->shouldReceive('getByValue')->once()->with('modrinth')->andReturn($modrinth);

        (new WarmProjectMetadata('modrinth', ['a']))->handle($registry);

        self::assertTrue(true);
    }

    public function test_handle_does_nothing_for_an_empty_id_list(): void
    {
        $registry = Mockery::mock(ProjectSourceRegistry::class);
        $registry->shouldNotReceive('getByValue');

        (new WarmProjectMetadata('modrinth', []))->handle($registry);

        self::assertTrue(true);
    }

    public function test_handle_skips_priming_when_the_source_is_unrecognized(): void
    {
        $registry = Mockery::mock(ProjectSourceRegistry::class);
        $registry->shouldReceive('getByValue')->once()->with('gone')->andReturnNull();

        (new WarmProjectMetadata('gone', ['a']))->handle($registry);

        self::assertTrue(true);
    }
}
