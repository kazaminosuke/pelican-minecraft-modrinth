<?php

namespace Kazaminosuke\ModManager\Support;

final class InstalledScanResult
{
    /** @param array<int, string> $unknownFiles */
    public function __construct(
        public readonly bool $successful,
        public readonly array $unknownFiles = [],
        public readonly int $diskFileCount = 0,
        public readonly bool $cacheHit = false,
        public readonly ?string $failure = null,
    ) {}

    /** @param array<int, string> $unknownFiles */
    public static function success(array $unknownFiles, int $diskFileCount): self
    {
        return new self(true, array_values($unknownFiles), $diskFileCount);
    }

    /** @param array<int, string> $unknownFiles */
    public static function failed(string $failure, array $unknownFiles = [], int $diskFileCount = 0): self
    {
        return new self(false, array_values($unknownFiles), $diskFileCount, failure: $failure);
    }

    public function asCacheHit(): self
    {
        return new self(
            successful: $this->successful,
            unknownFiles: $this->unknownFiles,
            diskFileCount: $this->diskFileCount,
            cacheHit: true,
            failure: $this->failure,
        );
    }

    /** @return array<string, mixed> */
    public function toCachePayload(): array
    {
        return [
            'schema_version' => 2,
            'successful' => $this->successful,
            'unknown_files' => $this->unknownFiles,
            'disk_file_count' => $this->diskFileCount,
        ];
    }

    public static function fromCache(mixed $payload): ?self
    {
        if (!is_array($payload)
            || ($payload['schema_version'] ?? null) !== 2
            || ($payload['successful'] ?? null) !== true
            || !isset($payload['unknown_files'], $payload['disk_file_count'])
            || !is_array($payload['unknown_files'])
            || !is_int($payload['disk_file_count'])) {
            return null;
        }

        $unknownFiles = [];
        foreach ($payload['unknown_files'] as $filename) {
            if (is_string($filename) && $filename !== '') {
                $unknownFiles[] = $filename;
            }
        }

        return (new self(
            successful: true,
            unknownFiles: $unknownFiles,
            diskFileCount: $payload['disk_file_count'],
        ))->asCacheHit();
    }
}
