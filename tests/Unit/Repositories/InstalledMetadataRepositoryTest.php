<?php

namespace Boy132\MinecraftModrinth\Tests\Unit\Repositories;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use Boy132\MinecraftModrinth\Repositories\InstalledMetadataRepository;
use Boy132\MinecraftModrinth\Support\InstalledMetadataDocument;
use Boy132\MinecraftModrinth\Support\InstalledMetadataReadStatus;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Http\Client\Response;
use Mockery;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3).'/src/Support/InstalledMetadataReadStatus.php';
require_once dirname(__DIR__, 3).'/src/Enums/ProjectSourceKey.php';
require_once dirname(__DIR__, 3).'/src/Support/InstalledMetadataDocument.php';
require_once dirname(__DIR__, 3).'/src/Support/InstalledMetadataReadResult.php';
require_once dirname(__DIR__, 3).'/src/Repositories/InstalledMetadataRepository.php';

class InstalledMetadataRepositoryTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_valid_empty_current_document_does_not_fall_back_to_legacy(): void
    {
        $server = $this->server();
        $files = Mockery::mock(DaemonFileRepository::class);
        $files->shouldReceive('setServer')->once()->with($server)->andReturnSelf();
        $files->shouldReceive('getContent')
            ->once()
            ->with('mods/.pelican-mod-manager.json')
            ->andReturn('{"installed_mods":[]}');
        $files->shouldNotReceive('getContent')->with('mods/.modrinth-metadata.json');

        $result = (new InstalledMetadataRepository())->read($server, $files, 'mods');

        self::assertSame(InstalledMetadataReadStatus::Current, $result->status);
        self::assertTrue($result->isAuthoritative());
        self::assertSame([], $result->document->installedMods());
    }

    public function test_missing_current_document_falls_back_to_legacy_and_defaults_source(): void
    {
        $server = $this->server();
        $files = Mockery::mock(DaemonFileRepository::class);
        $files->shouldReceive('setServer')->twice()->with($server)->andReturnSelf();
        $files->shouldReceive('getContent')
            ->once()
            ->with('mods/.pelican-mod-manager.json')
            ->andThrow(new FileNotFoundException());
        $files->shouldReceive('getContent')
            ->once()
            ->with('mods/.modrinth-metadata.json')
            ->andReturn(json_encode(['installed_mods' => [$this->legacyEntry()]], JSON_THROW_ON_ERROR));

        $result = (new InstalledMetadataRepository())->read($server, $files, 'mods');

        self::assertSame(InstalledMetadataReadStatus::Legacy, $result->status);
        self::assertSame('modrinth', $result->document->installedMods()[0]['source']);
    }

    public function test_invalid_documents_are_not_authoritative(): void
    {
        $server = $this->server();
        $files = Mockery::mock(DaemonFileRepository::class);
        $files->shouldReceive('setServer')->twice()->with($server)->andReturnSelf();
        $files->shouldReceive('getContent')->once()->andReturn('not-json');
        $files->shouldReceive('getContent')->once()->andThrow(new FileNotFoundException());

        $result = (new InstalledMetadataRepository())->read($server, $files, 'mods');

        self::assertSame(InstalledMetadataReadStatus::Invalid, $result->status);
        self::assertFalse($result->isAuthoritative());
    }

    public function test_v2_document_round_trip_preserves_signatures_hashes_and_unresolved_files(): void
    {
        $entry = $this->legacyEntry();
        $entry['file_signature'] = ['size' => 1234, 'modified_at' => '2026-07-30T00:00:00Z'];
        $entry['hashes'] = ['murmur2' => '42', 'sha512' => 'sha512', 'sha256' => 'sha256'];

        $document = InstalledMetadataDocument::fromArray([
            'schema_version' => 2,
            'installed_mods' => [$entry],
            'unresolved_files' => [[
                'filename' => 'unknown.jar',
                'file_signature' => ['size' => 5678, 'modified_at' => '2026-07-30T01:00:00Z'],
                'hashes' => ['murmur2' => '84', 'sha512' => 'other512', 'sha256' => 'other256'],
            ]],
        ]);

        self::assertNotNull($document);
        $roundTrip = InstalledMetadataDocument::fromJson(json_encode($document->toArray(), JSON_THROW_ON_ERROR));

        self::assertNotNull($roundTrip);
        self::assertSame(2, $roundTrip->toArray()['schema_version']);
        self::assertSame($entry['file_signature'], $roundTrip->installedMods()[0]['file_signature']);
        self::assertSame('unknown.jar', $roundTrip->unresolvedFiles()[0]['filename']);
    }

    public function test_bulk_replace_writes_once_and_bumps_hydration_once(): void
    {
        $server = $this->server();
        $files = Mockery::mock(DaemonFileRepository::class);
        $response = Mockery::mock(Response::class);
        $response->shouldReceive('failed')->once()->andReturnFalse();
        $files->shouldReceive('setServer')->once()->with($server)->andReturnSelf();
        $files->shouldReceive('putContent')
            ->once()
            ->with('mods/.pelican-mod-manager.json', Mockery::on(function (string $content): bool {
                $decoded = json_decode($content, true);

                return $decoded['schema_version'] === 2 && count($decoded['installed_mods']) === 2;
            }))
            ->andReturn($response);

        $repository = new SynchronousInstalledMetadataRepository();
        $document = InstalledMetadataDocument::fromArray([
            'installed_mods' => [$this->legacyEntry('one.jar'), $this->legacyEntry('two.jar')],
        ]);

        self::assertNotNull($document);
        self::assertTrue($repository->replace($server, $files, 'mods', $document));
        self::assertSame(1, $repository->hydrationBumps);
        self::assertSame(1, $repository->lockCalls);
    }

    public function test_failed_bulk_replace_does_not_bump_hydration(): void
    {
        $server = $this->server();
        $files = Mockery::mock(DaemonFileRepository::class);
        $response = Mockery::mock(Response::class);
        $response->shouldReceive('failed')->once()->andReturnTrue();
        $files->shouldReceive('setServer')->once()->with($server)->andReturnSelf();
        $files->shouldReceive('putContent')->once()->andReturn($response);

        $repository = new SynchronousInstalledMetadataRepository();

        self::assertFalse($repository->replace($server, $files, 'mods', InstalledMetadataDocument::empty()));
        self::assertSame(0, $repository->hydrationBumps);
    }

    protected function server(): Server
    {
        $server = new Server();
        $server->forceFill(['id' => 42]);

        return $server;
    }

    /** @return array<string, mixed> */
    protected function legacyEntry(string $filename = 'example.jar'): array
    {
        return [
            'project_id' => 'project',
            'project_slug' => 'project',
            'project_title' => 'Project',
            'version_id' => 'version',
            'version_number' => '1.0.0',
            'filename' => $filename,
            'installed_at' => '2026-07-30T00:00:00Z',
        ];
    }
}

class SynchronousInstalledMetadataRepository extends InstalledMetadataRepository
{
    public int $hydrationBumps = 0;

    public int $lockCalls = 0;

    protected function withinLock(Server $server, \Closure $callback): mixed
    {
        $this->lockCalls++;

        return $callback();
    }

    protected function bumpHydration(Server $server): void
    {
        $this->hydrationBumps++;
    }
}
