<?php

namespace Kazaminosuke\ModManager\Support;

final class ProjectIconUrl
{
    /** A local generic-image SVG for absent or failed remote icons. */
    private const PLACEHOLDER_DATA_URI = 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAyNCAyNCIgZmlsbD0ibm9uZSI+PHJlY3Qgd2lkdGg9IjI0IiBoZWlnaHQ9IjI0IiByeD0iMyIgZmlsbD0iI0YzRjRGNiIvPjxwYXRoIGQ9Im03IDE2IDMtMyAyIDIgMi0yIDMgMyIgc3Ryb2tlPSIjOUNBM0FGIiBzdHJva2Utd2lkdGg9IjEuNSIgc3Ryb2tlLWxpbmVjYXA9InJvdW5kIiBzdHJva2UtbGluZWpvaW49InJvdW5kIi8+PGNpcmNsZSBjeD0iOSIgY3k9IjkiIHI9IjEuMjUiIGZpbGw9IiM5Q0EzQUYiLz48L3N2Zz4=';

    /**
     * Native loading=lazy delayed first-view icons in this table's overflow
     * scroller: Chrome deprioritizes lazy images, so the visible rows waited
     * on layout/intersection instead of starting with the document parse.
     *
     * A page is 20 rows. The remaining viewport commonly shows 12-20 of them
     * (1080p ≈ 12, 1440p ≈ 18-20), so every on-page icon is eager. Measured
     * catalog transfer for 20 Modrinth icons is ~194KB; the extra ~80KB of
     * below-fold rows is cheaper than leaving visible icons lazy. The first
     * two rows use fetchpriority=high so they are not competing with the
     * rest for LCP.
     */
    public const EAGER_COUNT = 20;

    public const HIGH_PRIORITY_COUNT = 2;

    public static function placeholderDataUri(): string
    {
        return self::PLACEHOLDER_DATA_URI;
    }

    /**
     * @return array{loading: string, decoding: string, fetchpriority?: string}
     */
    public static function imgAttributes(int $rowIndex): array
    {
        $attributes = [
            'decoding' => 'async',
            'loading' => $rowIndex < self::EAGER_COUNT ? 'eager' : 'lazy',
        ];

        if ($rowIndex >= 0 && $rowIndex < self::HIGH_PRIORITY_COUNT) {
            $attributes['fetchpriority'] = 'high';
        }

        return $attributes;
    }

    /**
     * CurseForge's logo.thumbnailUrl is already a CDN rendition. Request its
     * small square variant for a dense catalog instead of transferring the
     * 256px thumbnail and shrinking it in the browser.
     */
    public static function curseForgeThumbnail(?string $url): ?string
    {
        if (!is_string($url) || $url === '') {
            return null;
        }

        return preg_replace(
            '#(/avatars/thumbnails/\d+/\d+)/\d+/\d+(/)#',
            '$1/64/64$2',
            $url,
        ) ?? $url;
    }

    /**
     * GitHub supports an avatar-size query. This source is direct-tracking
     * only, but its Installed rows use the same icon column as catalogs.
     */
    public static function githubAvatar(?string $url): ?string
    {
        if (!is_string($url) || $url === '') {
            return null;
        }

        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host']) || $parts['host'] !== 'avatars.githubusercontent.com') {
            return $url;
        }

        parse_str($parts['query'] ?? '', $query);
        $query['s'] = 64;

        return $parts['scheme'].'://'.$parts['host']
            .($parts['path'] ?? '')
            .'?'.http_build_query($query)
            .(isset($parts['fragment']) ? '#'.$parts['fragment'] : '');
    }
}
