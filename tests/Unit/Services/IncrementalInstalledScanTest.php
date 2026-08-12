<?php

namespace Kazaminosuke\ModManager\Tests\Unit\Services;

use App\Models\Server;
use Kazaminosuke\ModManager\Contracts\ProjectSourceInterface;
use Kazaminosuke\ModManager\Enums\ProjectType;
use Kazaminosuke\ModManager\Repositories\InstalledMetadataRepository;
use Kazaminosuke\ModManager\Services\InstalledProjectService;
use Kazaminosuke\ModManager\Support\InstalledMetadataDocument;
use Kazaminosuke\ModManager\Support\ProjectSourceRegistry;
use Mockery;
use PHPUnit\Framework\TestCase;

class IncrementalInstalledScanTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_hashes_are_reused_only_when_size_modified_time_and_all_algorithms_match(): void
    {
        $service = new TestableInstalledProjectService();
        $signature = ['size' => 123, 'modified_at' => '2026-07-30T00:00:00Z'];
        $hashes = ['murmur2' => '1', 'sha512' => 'two', 'sha256' => 'three'];
        $entry = ['file_signature' => $signature, 'hashes' => $hashes];

        self::assertSame($hashes, $service->exposeReusableHashes($entry, $signature));
        self::assertNull($service->exposeReusableHashes($entry, ['size' => 124, 'modified_at' => $signature['modified_at']]));
        self::assertNull($service->exposeReusableHashes([
            'file_signature' => $signature,
            'hashes' => ['sha512' => 'two', 'sha256' => 'three'],
        ], $signature));
    }

    public function test_scan_rebase_preserves_concurrent_updates_additions_and_removals(): void
    {
        $service = new TestableInstalledProjectService();
        $originalA = $this->entry('a', 'a.jar', '1');
        $originalB = $this->entry('b', 'b.jar', '1');
        $updatedB = $this->entry('b', 'b.jar', '2');
        $newC = $this->entry('c', 'c.jar', '1');

        $original = $this->document([$originalA, $originalB]);
        $latest = $this->document([$updatedB, $newC]);
        $rebased = $service->exposeRebase($original, $latest, [$originalA, $originalB], []);
        $byProject = [];

        foreach ($rebased->installedMods() as $entry) {
            $byProject[$entry['project_id']] = $entry;
        }

        self::assertArrayNotHasKey('a', $byProject);
        self::assertSame('2', $byProject['b']['version_id']);
        self::assertSame('1', $byProject['c']['version_id']);
    }

    public function test_hash_lookup_sources_follow_the_registry_project_type_and_enablement_rules(): void
    {
        $server = new Server();
        $server->forceFill(['id' => 42]);
        $curseForge = Mockery::mock(ProjectSourceInterface::class);
        $modrinth = Mockery::mock(ProjectSourceInterface::class);
        $github = Mockery::mock(ProjectSourceInterface::class);
        $curseForge->shouldReceive('supportsHashLookup')->once()->andReturnTrue();
        $modrinth->shouldReceive('supportsHashLookup')->once()->andReturnTrue();
        $github->shouldReceive('supportsHashLookup')->once()->andReturnFalse();
        $registry = Mockery::mock(ProjectSourceRegistry::class);
        $registry->shouldReceive('availableFor')
            ->once()
            ->with($server, ProjectType::Datapack)
            ->andReturn([$curseForge, $modrinth, $github]);

        $service = new TestableInstalledProjectService($registry);

        self::assertSame(
            [$curseForge, $modrinth],
            $service->exposeHashLookupSources($server, ProjectType::Datapack),
        );
    }

    /** @param array<int, array<string, mixed>> $entries */
    private function document(array $entries): InstalledMetadataDocument
    {
        $document = InstalledMetadataDocument::fromArray(['installed_mods' => $entries]);
        self::assertNotNull($document);

        return $document;
    }

    /** @return array<string, mixed> */
    private function entry(string $projectId, string $filename, string $versionId): array
    {
        return [
            'source' => 'modrinth',
            'project_id' => $projectId,
            'project_slug' => $projectId,
            'project_title' => strtoupper($projectId),
            'version_id' => $versionId,
            'version_number' => $versionId,
            'filename' => $filename,
            'installed_at' => '2026-07-30T00:00:00Z',
        ];
    }
}

class TestableInstalledProjectService extends InstalledProjectService
{
    public function __construct(?ProjectSourceRegistry $sourceRegistry = null)
    {
        if ($sourceRegistry !== null) {
            parent::__construct($sourceRegistry, Mockery::mock(InstalledMetadataRepository::class));
        }
    }

    /** @param array<string, mixed>|null $signature */
    public function exposeReusableHashes(array $entry, ?array $signature): ?array
    {
        return $this->reusableHashes($entry, $signature);
    }

    /**
     * @param array<int, array<string, mixed>> $installed
     * @param array<int, array<string, mixed>> $unresolved
     */
    public function exposeRebase(InstalledMetadataDocument $original, InstalledMetadataDocument $latest, array $installed, array $unresolved): InstalledMetadataDocument
    {
        return $this->rebaseScanDocument($original, $latest, $installed, $unresolved);
    }

    /** @return array<int, ProjectSourceInterface> */
    public function exposeHashLookupSources(Server $server, ProjectType $type): array
    {
        return $this->getHashLookupSourcesInPriorityOrder($server, $type);
    }
}
