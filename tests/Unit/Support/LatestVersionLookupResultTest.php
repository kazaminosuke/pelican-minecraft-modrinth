<?php

namespace Kazaminosuke\ModManager\Tests\Unit\Support;

use Kazaminosuke\ModManager\Support\LatestVersionLookupRequest;
use Kazaminosuke\ModManager\Support\LatestVersionLookupResult;
use PHPUnit\Framework\TestCase;

class LatestVersionLookupResultTest extends TestCase
{
    public function test_pending_keys_are_deduplicated_and_queryable(): void
    {
        $result = new LatestVersionLookupResult(pendingKeys: ['modrinth:a', 'modrinth:a', 'modrinth:b']);

        self::assertSame(['modrinth:a', 'modrinth:b'], $result->pendingKeys());
        self::assertTrue($result->isPending('modrinth:a'));
        self::assertFalse($result->isPending('modrinth:c'));
    }

    public function test_merge_keeps_pending_keys_not_settled_by_either_side(): void
    {
        $left = new LatestVersionLookupResult(pendingKeys: ['modrinth:a', 'modrinth:b']);
        $right = new LatestVersionLookupResult(pendingKeys: ['curseforge:c']);

        $merged = $left->merge($right);

        self::assertSame(['modrinth:a', 'modrinth:b', 'curseforge:c'], $merged->pendingKeys());
    }

    public function test_a_resolved_version_takes_precedence_over_a_pending_mark_from_either_side(): void
    {
        $pending = new LatestVersionLookupResult(pendingKeys: ['modrinth:a']);
        $resolved = new LatestVersionLookupResult(versionsByKey: ['modrinth:a' => ['id' => 'v1']]);

        $mergedPendingFirst = $pending->merge($resolved);
        $mergedResolvedFirst = $resolved->merge($pending);

        self::assertFalse($mergedPendingFirst->isPending('modrinth:a'));
        self::assertSame('v1', $mergedPendingFirst->version('modrinth:a')['id']);
        self::assertFalse($mergedResolvedFirst->isPending('modrinth:a'));
        self::assertSame('v1', $mergedResolvedFirst->version('modrinth:a')['id']);
    }

    public function test_an_unresolved_or_failed_key_also_takes_precedence_over_pending(): void
    {
        $pending = new LatestVersionLookupResult(pendingKeys: ['modrinth:a', 'modrinth:b']);
        $unresolved = new LatestVersionLookupResult(unresolvedKeys: ['modrinth:a']);
        $failed = LatestVersionLookupResult::failed(
            [new LatestVersionLookupRequest('modrinth', 'b')],
            'boom',
        );

        $merged = $pending->merge($unresolved)->merge($failed);

        self::assertFalse($merged->isPending('modrinth:a'));
        self::assertFalse($merged->isPending('modrinth:b'));
        self::assertSame(['modrinth:a'], $merged->unresolvedKeys());
        self::assertArrayHasKey('modrinth:b', $merged->failures());
    }
}
