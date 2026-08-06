<?php

namespace Kazaminosuke\ModManager\Tests\Unit\Support;

use Kazaminosuke\ModManager\Enums\ProjectType;
use Kazaminosuke\ModManager\Support\InstalledOperationState;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use stdClass;

require_once dirname(__DIR__, 3).'/src/Enums/ProjectType.php';
require_once dirname(__DIR__, 3).'/src/Support/InstalledOperationState.php';

class InstalledOperationStateTest extends TestCase
{
    public function test_state_transitions_round_trip_through_safe_cache_payload(): void
    {
        $queuedAt = new DateTimeImmutable('2026-07-30T01:00:00+00:00');
        $finishedAt = new DateTimeImmutable('2026-07-30T01:02:00+00:00');

        $state = InstalledOperationState::queued(
            operation: 'scan',
            serverId: 42,
            projectType: ProjectType::Mod,
            now: $queuedAt,
        )
            ->running(452, $queuedAt)
            ->withProgress(120, 452)
            ->completed([
                'disk_file_count' => 452,
                'unknown_files_count' => 3,
            ], $finishedAt);

        $restored = InstalledOperationState::fromCachePayload($state->toCachePayload());

        self::assertNotNull($restored);
        self::assertSame(InstalledOperationState::STATUS_COMPLETED, $restored->status);
        self::assertSame(452, $restored->progress);
        self::assertSame(452, $restored->total);
        self::assertSame(3, $restored->result['unknown_files_count']);
        self::assertSame('2026-07-30T01:02:00+00:00', $restored->finishedAt);
    }

    public function test_unsafe_result_values_and_error_details_are_not_cached(): void
    {
        $resource = fopen('php://memory', 'r');
        $state = InstalledOperationState::queued(
            operation: 'scan',
            serverId: 42,
            projectType: ProjectType::Mod,
        )->failed(
            'Wings returned /api/servers/secret path',
            [
                'safe_count' => 5,
                'exception' => new stdClass(),
                'resource' => $resource,
            ],
        );
        fclose($resource);

        $payload = $state->toCachePayload();

        self::assertSame('Wings_returned_api_servers_secret_path', $payload['error']);
        self::assertSame(['safe_count' => 5], $payload['result']);
    }

    public function test_invalid_or_old_cache_payload_is_rejected(): void
    {
        self::assertNull(InstalledOperationState::fromCachePayload([]));
        self::assertNull(InstalledOperationState::fromCachePayload([
            'schema_version' => 0,
        ]));
    }
}
