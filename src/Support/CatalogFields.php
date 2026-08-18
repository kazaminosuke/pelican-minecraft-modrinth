<?php

namespace Kazaminosuke\ModManager\Support;

/**
 * Shared catalog-row field shaping. Search hits are stored in Redis and
 * serialized into the Livewire table payload, so only values the table
 * actually renders belong here.
 */
final class CatalogFields
{
    /**
     * Matches ModManagerPage::truncateProjectDescription(). Storing more
     * than the table will display inflates every catalog cache entry and
     * Livewire morph without changing what the user sees.
     */
    public const DESCRIPTION_MAX_LENGTH = 120;

    public static function description(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }

        $value = trim($value);
        if ($value === '') {
            return '';
        }

        if (mb_strlen($value) <= self::DESCRIPTION_MAX_LENGTH) {
            return $value;
        }

        return mb_substr($value, 0, self::DESCRIPTION_MAX_LENGTH);
    }
}
