<?php

namespace Kazaminosuke\ModManager\Support;

enum InstalledMetadataReadStatus: string
{
    case Current = 'current';
    case Legacy = 'legacy';
    case Missing = 'missing';
    case Invalid = 'invalid';
    case Unavailable = 'unavailable';

    public function isAuthoritative(): bool
    {
        return $this === self::Current || $this === self::Legacy;
    }
}
