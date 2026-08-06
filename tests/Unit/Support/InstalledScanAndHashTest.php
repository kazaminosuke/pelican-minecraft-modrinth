<?php

namespace Kazaminosuke\ModManager\Tests\Unit\Support;

use Kazaminosuke\ModManager\Support\CurseForgeFingerprint;
use Kazaminosuke\ModManager\Support\InstalledScanResult;
use PHPUnit\Framework\TestCase;

class InstalledScanAndHashTest extends TestCase
{
    public function test_only_successful_scan_results_can_be_restored_from_cache(): void
    {
        $successful = InstalledScanResult::success([], 0);
        $cached = InstalledScanResult::fromCache($successful->toCachePayload());

        self::assertNotNull($cached);
        self::assertTrue($cached->successful);
        self::assertTrue($cached->cacheHit);
        self::assertSame(0, $cached->diskFileCount);
        self::assertSame([], $cached->unknownFiles);

        self::assertNull(InstalledScanResult::fromCache([
            'schema_version' => 2,
            'successful' => false,
            'unknown_files' => [],
            'disk_file_count' => 0,
        ]));

        $partialFailure = InstalledScanResult::failed('hash_computation_partial_failure', ['broken.jar'], 2);

        self::assertSame(['broken.jar'], $partialFailure->unknownFiles);
        self::assertSame(2, $partialFailure->diskFileCount);
        self::assertNull(InstalledScanResult::fromCache($partialFailure->toCachePayload()));
    }

    public function test_stream_is_opened_once_while_murmur_and_crypto_hashes_share_raw_chunks(): void
    {
        $chunks = ["hello \n", "world\t!\r"];
        $raw = implode('', $chunks);
        $stream = new ArrayReadableStream($chunks);
        $opens = 0;
        $sha512 = hash_init('sha512');
        $sha256 = hash_init('sha256');

        $fingerprint = CurseForgeFingerprint::hashStream(
            function () use ($stream, &$opens): ArrayReadableStream {
                $opens++;

                return $stream;
            },
            static function (string $chunk) use ($sha512, $sha256): void {
                hash_update($sha512, $chunk);
                hash_update($sha256, $chunk);
            },
        );

        self::assertSame(1, $opens);
        self::assertTrue($stream->closed);
        self::assertSame(CurseForgeFingerprint::hash($raw), $fingerprint);
        self::assertSame(hash('sha512', $raw), hash_final($sha512));
        self::assertSame(hash('sha256', $raw), hash_final($sha256));
    }
}

class ArrayReadableStream
{
    private int $offset = 0;

    public bool $closed = false;

    /** @param array<int, string> $chunks */
    public function __construct(private readonly array $chunks) {}

    public function eof(): bool
    {
        return $this->offset >= count($this->chunks);
    }

    public function read(int $length): string
    {
        return $this->chunks[$this->offset++] ?? '';
    }

    public function close(): void
    {
        $this->closed = true;
    }
}
