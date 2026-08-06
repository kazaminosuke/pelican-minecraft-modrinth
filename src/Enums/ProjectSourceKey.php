<?php

namespace Kazaminosuke\ModManager\Enums;

enum ProjectSourceKey: string
{
    case Modrinth = 'modrinth';
    case CurseForge = 'curseforge';
    case Hangar = 'hangar';
    case GitHubReleases = 'github_releases';
    case Voxel = 'voxel';
}
