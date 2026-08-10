<?php

namespace Kazaminosuke\ModManager\Tests\Unit\Filament;

use Illuminate\Config\Repository as LaravelConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
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

    public static function navigationSortForTest(ProjectType $type): int
    {
        return static::navigationSortFor($type);
    }

    public function markOperationHandledForTest(InstalledOperationState $state): void
    {
        $this->markInstalledOperationHandled($state);
    }

    public function shouldPollForTest(?InstalledOperationState $state): bool
    {
        return $this->shouldPollInstalledOperation($state);
    }

    public function applyInstalledOperationForTest(?InstalledOperationState $state): void
    {
        $this->setInstalledOperationState($state);
    }

    public function rememberScanCompletionForTest(InstalledOperationState $state): void
    {
        $this->rememberInstalledScanCompletion($state);
    }

    public function shouldShowOperationStatusForTest(): bool
    {
        return $this->shouldShowInstalledOperationStatus();
    }

    /** @return array<string, string> */
    public function operationStatusAttributesForTest(): array
    {
        return $this->installedOperationStatusExtraAttributes();
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

    public function test_scan_status_is_visible_only_when_installed_tab_observes_the_scan(): void
    {
        $page = new TestableModManagerPage();
        $scan = InstalledOperationState::queued(
            InstalledOperationManager::OPERATION_SCAN,
            42,
            ProjectType::Plugin,
        );

        $page->activeTab = ProjectSourceKey::Modrinth->value;
        $page->applyInstalledOperationForTest($scan);
        self::assertFalse($page->shouldShowOperationStatusForTest());
        self::assertNull($page->observedInstalledScan);

        $page->activeTab = 'installed';
        $page->applyInstalledOperationForTest($scan);
        self::assertTrue($page->shouldShowOperationStatusForTest());
        self::assertNotNull($page->observedInstalledScan);
    }

    public function test_scan_completion_is_short_lived_and_uses_an_absolute_deadline(): void
    {
        $page = new TestableModManagerPage();
        $page->activeTab = 'installed';
        $scan = InstalledOperationState::queued(
            InstalledOperationManager::OPERATION_SCAN,
            42,
            ProjectType::Datapack,
            now: new \DateTimeImmutable('-1 minute'),
        );
        $page->applyInstalledOperationForTest($scan);
        $completed = $scan->completed([], new \DateTimeImmutable('-1 second'));
        $page->applyInstalledOperationForTest($completed);
        $page->rememberScanCompletionForTest($completed);

        self::assertTrue($page->shouldShowOperationStatusForTest());
        $firstAttributes = $page->operationStatusAttributesForTest();
        $secondAttributes = $page->operationStatusAttributesForTest();
        self::assertSame($firstAttributes, $secondAttributes);
        self::assertArrayHasKey('x-init', $firstAttributes);
        self::assertStringContainsString('Date.now()', $firstAttributes['x-init']);

        $page->activeTab = ProjectSourceKey::Modrinth->value;
        self::assertFalse($page->shouldShowOperationStatusForTest());
    }

    public function test_expired_scan_completion_is_not_rendered(): void
    {
        $page = new TestableModManagerPage();
        $page->activeTab = 'installed';
        $scan = InstalledOperationState::queued(
            InstalledOperationManager::OPERATION_SCAN,
            42,
            ProjectType::Datapack,
            now: new \DateTimeImmutable('-2 minutes'),
        );
        $page->applyInstalledOperationForTest($scan);
        $completed = $scan->completed([], new \DateTimeImmutable('-30 seconds'));
        $page->applyInstalledOperationForTest($completed);
        $page->rememberScanCompletionForTest($completed);

        self::assertFalse($page->shouldShowOperationStatusForTest());
        self::assertSame([], $page->operationStatusAttributesForTest());
    }

    public function test_bulk_update_status_remains_rendered(): void
    {
        $page = new TestableModManagerPage();

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

    public function test_multi_source_catalog_defaults_to_the_first_visible_source(): void
    {
        $page = $this->pageWithSources([
            $this->source(ProjectSourceKey::CurseForge, supportsSearch: true),
            $this->source(ProjectSourceKey::Modrinth, supportsSearch: true),
        ]);

        self::assertSame(ProjectSourceKey::CurseForge->value, $page->getDefaultActiveTab());
    }

    public function test_out_of_range_table_pages_are_clamped_to_the_last_real_page(): void
    {
        $page = new TestableModManagerPage();

        self::assertSame(71, $page->clampTablePageForTest(72, 1416));
        self::assertSame(71, $page->clampTablePageForTest(71, 1416));
        self::assertSame(1, $page->clampTablePageForTest(4, 0));
    }

    public function test_navigation_sort_uses_a_distinct_setting_for_each_project_type(): void
    {
        $previousContainer = Container::getInstance();
        $previousFacadeApplication = Facade::getFacadeApplication();
        $container = new Container();
        $config = new LaravelConfigRepository([
            'pelican-minecraft-modrinth' => [
                'navigation_sort' => [
                    'mod' => 10,
                    'plugin' => 20,
                    'datapack' => 30,
                ],
            ],
        ]);
        $container->instance('config', $config);
        Container::setInstance($container);
        Facade::setFacadeApplication($container);

        try {
            self::assertSame(10, TestableModManagerPage::navigationSortForTest(ProjectType::Mod));
            self::assertSame(20, TestableModManagerPage::navigationSortForTest(ProjectType::Plugin));
            self::assertSame(30, TestableModManagerPage::navigationSortForTest(ProjectType::Datapack));
        } finally {
            Container::setInstance($previousContainer);
            Facade::setFacadeApplication($previousFacadeApplication);
        }
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
