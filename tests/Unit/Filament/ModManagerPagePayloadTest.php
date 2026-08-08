<?php

namespace Kazaminosuke\ModManager\Tests\Unit\Filament;

use Kazaminosuke\ModManager\Filament\Server\Pages\ModManagerPage;
use Kazaminosuke\ModManager\Support\InstalledScanResult;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

class ModManagerPagePayloadTest extends TestCase
{
    public function test_unknown_files_are_not_part_of_the_livewire_snapshot(): void
    {
        $property = new ReflectionProperty(ModManagerPage::class, 'unknownFiles');

        self::assertTrue($property->isProtected());
        self::assertFalse($property->isPublic());
    }

    public function test_persisted_scan_count_invalidates_the_memoized_tab_definition(): void
    {
        $page = new class extends ModManagerPage {
            public function primeTabsForTest(): void
            {
                $this->cachedTabs = [];
            }

            public function applyScanResultForTest(?InstalledScanResult $scanResult): void
            {
                $this->setInstalledScanResult($scanResult);
            }

            public function hasCachedTabsForTest(): bool
            {
                return isset($this->cachedTabs);
            }
        };

        $page->primeTabsForTest();
        self::assertTrue($page->hasCachedTabsForTest());

        $page->applyScanResultForTest(InstalledScanResult::success([], 4));

        self::assertSame(4, $page->installedFilesCount);
        self::assertTrue($page->installedScanDataReady);
        self::assertFalse($page->hasCachedTabsForTest());
    }
}
