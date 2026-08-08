<?php

namespace Kazaminosuke\ModManager\Tests\Unit\Filament;

use Kazaminosuke\ModManager\Enums\ProjectType;
use Kazaminosuke\ModManager\Filament\Server\Pages\ModManagerPage;
use Kazaminosuke\ModManager\Services\InstalledOperationManager;
use Kazaminosuke\ModManager\Support\InstalledOperationState;
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

    public function test_catalog_tab_keeps_polling_an_active_automatic_scan(): void
    {
        $page = new class extends ModManagerPage {
            public function shouldPollForTest(?InstalledOperationState $state): bool
            {
                return $this->shouldPollInstalledOperation($state);
            }
        };
        $page->activeTab = 'modrinth';

        $state = InstalledOperationState::queued(
            InstalledOperationManager::OPERATION_SCAN,
            42,
            ProjectType::Mod,
        );

        self::assertTrue($page->shouldPollForTest($state));
    }

    public function test_scan_start_notification_is_claimed_once_per_operation(): void
    {
        $page = new class extends ModManagerPage {
            public function claimScanStartNotificationForTest(InstalledOperationState $state): bool
            {
                return $this->claimInstalledScanStartNotification($state);
            }
        };
        $scan = InstalledOperationState::queued(
            InstalledOperationManager::OPERATION_SCAN,
            42,
            ProjectType::Plugin,
        );

        self::assertTrue($page->claimScanStartNotificationForTest($scan));
        self::assertFalse($page->claimScanStartNotificationForTest($scan->running()));
        self::assertTrue($page->claimScanStartNotificationForTest(InstalledOperationState::queued(
            InstalledOperationManager::OPERATION_SCAN,
            42,
            ProjectType::Plugin,
            now: new \DateTimeImmutable('+1 minute'),
        )));
    }

    public function test_scan_status_is_not_rendered_in_the_page_body(): void
    {
        $page = new class extends ModManagerPage {
            public function shouldShowOperationStatusForTest(): bool
            {
                return $this->shouldShowInstalledOperationStatus();
            }
        };

        $page->installedOperation = InstalledOperationState::queued(
            InstalledOperationManager::OPERATION_SCAN,
            42,
            ProjectType::Datapack,
        )->toCachePayload();
        self::assertFalse($page->shouldShowOperationStatusForTest());

        $page->installedOperation = InstalledOperationState::queued(
            InstalledOperationManager::OPERATION_BULK_UPDATE,
            42,
            ProjectType::Datapack,
        )->toCachePayload();
        self::assertTrue($page->shouldShowOperationStatusForTest());
    }
}
