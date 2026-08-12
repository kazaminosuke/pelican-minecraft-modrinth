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

    public function test_curseforge_urls_without_a_thumbnail_dimension_are_preserved(): void
    {
        $url = 'https://media.forgecdn.net/avatars/308/39/project.png';

        self::assertSame($url, ProjectIconUrl::curseForgeThumbnail($url));
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
}
