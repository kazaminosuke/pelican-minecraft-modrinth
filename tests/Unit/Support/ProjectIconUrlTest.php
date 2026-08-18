<?php

namespace Kazaminosuke\ModManager\Tests\Unit\Support;

use Kazaminosuke\ModManager\Support\ProjectIconUrl;
use PHPUnit\Framework\TestCase;

class ProjectIconUrlTest extends TestCase
{
    public function test_the_local_placeholder_is_deterministic(): void
    {
        $placeholder = ProjectIconUrl::placeholderDataUri();

        self::assertStringStartsWith('data:image/svg+xml;base64,', $placeholder);
    }

    public function test_curseforge_catalog_icons_use_the_cdn_small_rendition(): void
    {
        $url = 'https://media.forgecdn.net/avatars/thumbnails/308/39/256/256/637390518902846753.png';

        self::assertSame(
            'https://media.forgecdn.net/avatars/thumbnails/308/39/64/64/637390518902846753.png',
            ProjectIconUrl::curseForgeThumbnail($url),
        );
    }

    public function test_curseforge_original_avatars_are_rewritten_to_the_small_thumbnail(): void
    {
        $url = 'https://media.forgecdn.net/avatars/68/303/636163127747978216.png';

        self::assertSame(
            'https://media.forgecdn.net/avatars/thumbnails/68/303/64/64/636163127747978216.png',
            ProjectIconUrl::curseForgeThumbnail($url),
        );
        self::assertNull(ProjectIconUrl::curseForgeThumbnail(null));
    }

    public function test_github_avatar_icons_request_a_smaller_upstream_rendition_without_losing_query_parameters(): void
    {
        $url = 'https://avatars.githubusercontent.com/u/12345?v=4&s=460';

        self::assertSame(
            'https://avatars.githubusercontent.com/u/12345?v=4&s=64',
            ProjectIconUrl::githubAvatar($url),
        );
    }

    public function test_non_github_icons_are_preserved(): void
    {
        $url = 'https://cdn.modrinth.com/data/example/icon.png';

        self::assertSame($url, ProjectIconUrl::githubAvatar($url));
        self::assertNull(ProjectIconUrl::githubAvatar(null));
    }

    public function test_on_page_icons_are_eager_and_the_first_rows_are_high_priority(): void
    {
        $first = ProjectIconUrl::imgAttributes(0);
        $second = ProjectIconUrl::imgAttributes(1);
        $lastHigh = ProjectIconUrl::imgAttributes(ProjectIconUrl::HIGH_PRIORITY_COUNT - 1);
        $later = ProjectIconUrl::imgAttributes(ProjectIconUrl::HIGH_PRIORITY_COUNT);
        $lastOnPage = ProjectIconUrl::imgAttributes(ProjectIconUrl::EAGER_COUNT - 1);
        $offPage = ProjectIconUrl::imgAttributes(ProjectIconUrl::EAGER_COUNT);

        self::assertSame('eager', $first['loading']);
        self::assertSame('async', $first['decoding']);
        self::assertSame('high', $first['fetchpriority']);
        self::assertSame('high', $second['fetchpriority']);
        self::assertSame('high', $lastHigh['fetchpriority']);
        self::assertArrayNotHasKey('fetchpriority', $later);
        self::assertSame('eager', $later['loading']);
        self::assertSame('eager', $lastOnPage['loading']);
        self::assertSame('lazy', $offPage['loading']);
        self::assertArrayNotHasKey('fetchpriority', $offPage);
    }
}
