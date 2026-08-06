<?php

namespace Kazaminosuke\ModManager\Tests\Unit\Services;

use Kazaminosuke\ModManager\Services\InstalledProjectService;
use Kazaminosuke\ModManager\Support\InstalledMetadataDocument;
use PHPUnit\Framework\TestCase;

class IncrementalInstalledScanTest extends TestCase
{
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
    public function __construct() {}

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
}
