<?php

namespace Kazaminosuke\ModManager\Support;

/**
 * Computes CurseForge's file "fingerprint": a MurmurHash2 (32-bit, seed 1) over
 * the file's bytes with whitespace bytes (tab, LF, CR, space) stripped out first.
 *
 * This matches the algorithm CurseForge itself uses to identify files (see
 * POST /v1/fingerprints), independently confirmed against community references
 * (e.g. packwiz's curseforge/murmur2 package) and the core MurmurHash2 mixing
 * function verified against Apache Commons Codec's published test vectors.
 */
class CurseForgeFingerprint
{
    private const SEED = 1;

    private const M = 0x5bd1e995;

    private const R = 24;

    public static function hash(string $content): int
    {
        $filtered = str_replace(["\x09", "\x0A", "\x0D", "\x20"], '', $content);

        return self::murmurHash2($filtered, self::SEED);
    }

    /**
     * Computes a fingerprint with one read of the source stream.
     *
     * MurmurHash2 needs the filtered length before hashing. Filtered bytes are
     * therefore spooled to php://temp, which stays in memory only up to 2 MiB
     * and transparently spills larger JARs to disk for the local second pass.
     * The optional callback receives each raw chunk during the single source
     * read so callers can calculate cryptographic hashes at the same time.
     */
    public static function hashStream(callable $openStream, ?callable $consumeRawChunk = null): int
    {
        $filteredStream = fopen('php://temp/maxmemory:2097152', 'w+b');

        if ($filteredStream === false) {
            throw new \RuntimeException('Unable to create temporary stream for CurseForge fingerprint');
        }

        try {
            $length = 0;
            $sourceStream = $openStream();

            try {
                while (!$sourceStream->eof()) {
                    $chunk = $sourceStream->read(1024 * 1024);

                    if ($chunk === '') {
                        continue;
                    }

                    if ($consumeRawChunk !== null) {
                        $consumeRawChunk($chunk);
                    }

                    $filtered = str_replace(["\x09", "\x0A", "\x0D", "\x20"], '', $chunk);
                    $length += strlen($filtered);
                    self::writeAll($filteredStream, $filtered);
                }
            } finally {
                $sourceStream->close();
            }

            if (!rewind($filteredStream)) {
                throw new \RuntimeException('Unable to rewind CurseForge fingerprint data');
            }

            return self::murmurHash2Stream($filteredStream, $length);
        } finally {
            fclose($filteredStream);
        }
    }

    /** @param resource $stream */
    private static function writeAll($stream, string $data): void
    {
        $length = strlen($data);
        $offset = 0;

        while ($offset < $length) {
            $written = fwrite($stream, substr($data, $offset));

            if ($written === false || $written === 0) {
                throw new \RuntimeException('Unable to spool CurseForge fingerprint data');
            }

            $offset += $written;
        }
    }

    /** @param resource $stream */
    private static function murmurHash2Stream($stream, int $length): int
    {
        $h = (self::SEED ^ $length) & 0xFFFFFFFF;
        $remainder = '';

        while (!feof($stream)) {
            $chunk = fread($stream, 1024 * 1024);

            if ($chunk === false) {
                throw new \RuntimeException('Unable to read spooled CurseForge fingerprint data');
            }

            if ($chunk === '') {
                continue;
            }

            $data = $remainder . $chunk;
            $processableLength = strlen($data) - (strlen($data) % 4);

            for ($i = 0; $i < $processableLength; $i += 4) {
                $k = (ord($data[$i]) | (ord($data[$i + 1]) << 8) | (ord($data[$i + 2]) << 16) | (ord($data[$i + 3]) << 24)) & 0xFFFFFFFF;
                $k = ($k * self::M) & 0xFFFFFFFF;
                $k ^= $k >> self::R;
                $k = ($k * self::M) & 0xFFFFFFFF;
                $h = ($h * self::M) & 0xFFFFFFFF;
                $h ^= $k;
            }

            $remainder = substr($data, $processableLength);
        }

        $remaining = strlen($remainder);

        if ($remaining === 3) {
            $h ^= ord($remainder[2]) << 16;
        }
        if ($remaining >= 2) {
            $h ^= ord($remainder[1]) << 8;
        }
        if ($remaining >= 1) {
            $h ^= ord($remainder[0]);
            $h = ($h * self::M) & 0xFFFFFFFF;
        }

        $h ^= $h >> 13;
        $h = ($h * self::M) & 0xFFFFFFFF;
        $h ^= $h >> 15;

        return $h;
    }

    private static function murmurHash2(string $data, int $seed): int
    {
        $length = strlen($data);
        $h = ($seed ^ $length) & 0xFFFFFFFF;

        $i = 0;
        while ($length - $i >= 4) {
            $k = (ord($data[$i]) | (ord($data[$i + 1]) << 8) | (ord($data[$i + 2]) << 16) | (ord($data[$i + 3]) << 24)) & 0xFFFFFFFF;

            $k = ($k * self::M) & 0xFFFFFFFF;
            $k ^= $k >> self::R;
            $k = ($k * self::M) & 0xFFFFFFFF;

            $h = ($h * self::M) & 0xFFFFFFFF;
            $h ^= $k;

            $i += 4;
        }

        $remaining = $length - $i;

        if ($remaining === 3) {
            $h ^= ord($data[$i + 2]) << 16;
        }
        if ($remaining >= 2) {
            $h ^= ord($data[$i + 1]) << 8;
        }
        if ($remaining >= 1) {
            $h ^= ord($data[$i]);
            $h = ($h * self::M) & 0xFFFFFFFF;
        }

        $h ^= $h >> 13;
        $h = ($h * self::M) & 0xFFFFFFFF;
        $h ^= $h >> 15;

        return $h;
    }
}
