<?php

namespace Kazaminosuke\ModManager\Tests\Unit\Filament;

use Kazaminosuke\ModManager\Contracts\ProjectSourceInterface;
use Kazaminosuke\ModManager\Enums\ProjectSourceKey;
use Kazaminosuke\ModManager\Enums\ProjectType;
use Kazaminosuke\ModManager\Filament\Server\Pages\ModManagerPage;
use Kazaminosuke\ModManager\Services\InstalledOperationManager;
use Kazaminosuke\ModManager\Support\InstalledOperationState;
use Kazaminosuke\ModManager\Support\InstalledScanResult;
use Mockery;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

final class TestableModManagerPage extends ModManagerPage
{
    /** @param array<int, ProjectSourceInterface> $sources */
    public function __construct(private readonly array $sources = []) {}

    protected function getAvailableSources(): array
    {
        return $this->sources;
    }

    /** @return array<int, string> */
    public function catalogSourceKeysForTest(): array
    {
        return array_map(
            static fn (ProjectSourceInterface $source): string => $source->getKey()->value,
            $this->getCatalogSources(),
        );
    }

    public function clampTablePageForTest(int $page, int $total): int
    {
        return $this->clampTablePage($page, $total);
    }

    public function markOperationHandledForTest(InstalledOperationState $state): void
    {
        $this->markInstalledOperationHandled($state);
    }

    public function shouldPollForTest(?InstalledOperationState $state): bool
    {
        return $this->shouldPollInstalledOperation($state);
    }
}

class ModManagerPagePayloadTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

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

    public function test_catalog_tabs_exclude_sources_without_search_capability(): void
    {
        $page = $this->pageWithSources([
            $this->source(ProjectSourceKey::Modrinth, supportsSearch: true),
            $this->source(ProjectSourceKey::GitHubReleases, supportsSearch: false),
        ]);

        self::assertSame([ProjectSourceKey::Modrinth->value], $page->catalogSourceKeysForTest());
    }

    public function test_multi_source_catalog_defaults_to_modrinth(): void
    {
        $page = $this->pageWithSources([
            $this->source(ProjectSourceKey::CurseForge, supportsSearch: true),
            $this->source(ProjectSourceKey::Modrinth, supportsSearch: true),
        ]);

        self::assertSame(ProjectSourceKey::Modrinth->value, $page->getDefaultActiveTab());
    }

    public function test_out_of_range_table_pages_are_clamped_to_the_last_real_page(): void
    {
        $page = new TestableModManagerPage();

        self::assertSame(71, $page->clampTablePageForTest(72, 1416));
        self::assertSame(71, $page->clampTablePageForTest(71, 1416));
        self::assertSame(1, $page->clampTablePageForTest(4, 0));
    }

    public function test_terminal_dispatch_failure_is_marked_handled_without_polling_again(): void
    {
        $page = new TestableModManagerPage();
        $page->pollInstalledOperations = true;
        $failed = InstalledOperationState::queued(
            InstalledOperationManager::OPERATION_SCAN,
            42,
            ProjectType::Datapack,
        )->failed('dispatch_failed');

        $page->markOperationHandledForTest($failed);

        self::assertSame($failed->operation.':'.$failed->finishedAt, $page->handledInstalledOperation);
        self::assertFalse($page->pollInstalledOperations);
        self::assertFalse($page->shouldPollForTest($failed));
    }

    /** @param array<int, ProjectSourceInterface> $sources */
    private function pageWithSources(array $sources): TestableModManagerPage
    {
        return new TestableModManagerPage($sources);
    }

    private function source(ProjectSourceKey $key, bool $supportsSearch): ProjectSourceInterface
    {
        $source = Mockery::mock(ProjectSourceInterface::class);
        $source->shouldReceive('getKey')->andReturn($key);
        $source->shouldReceive('supportsSearch')->andReturn($supportsSearch);

        return $source;
    }
}
