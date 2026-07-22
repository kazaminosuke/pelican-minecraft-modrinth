<?php

namespace Boy132\MinecraftModrinth\Support;

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
     * Computes a fingerprint through bounded reads from a stream factory.
     * MurmurHash2 needs the filtered length first, so it makes two streaming
     * passes without retaining the JAR contents in PHP memory.
     */
    public static function hashStream(callable $openStream): int
    {
        $length = 0;
        self::consumeFilteredChunks($openStream, function (string $chunk) use (&$length): void { $length += strlen($chunk); });

        $h = (self::SEED ^ $length) & 0xFFFFFFFF;
        $remainder = '';
        self::consumeFilteredChunks($openStream, function (string $chunk) use (&$h, &$remainder): void {
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
        });

        $remaining = strlen($remainder);
        if ($remaining === 3) { $h ^= ord($remainder[2]) << 16; }
        if ($remaining >= 2) { $h ^= ord($remainder[1]) << 8; }
        if ($remaining >= 1) { $h ^= ord($remainder[0]); $h = ($h * self::M) & 0xFFFFFFFF; }
        $h ^= $h >> 13;
        $h = ($h * self::M) & 0xFFFFFFFF;
        $h ^= $h >> 15;

        return $h;
    }

    private static function consumeFilteredChunks(callable $openStream, callable $consume): void
    {
        $stream = $openStream();
        try {
            while (!$stream->eof()) {
                $chunk = $stream->read(1024 * 1024);
                if ($chunk !== '') { $consume(str_replace(["\x09", "\x0A", "\x0D", "\x20"], '', $chunk)); }
            }
        } finally {
            $stream->close();
        }
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
