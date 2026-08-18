<?php

namespace Kazaminosuke\ModManager\Tests\Unit\Support;

use Kazaminosuke\ModManager\Support\CatalogFields;
use PHPUnit\Framework\TestCase;

class CatalogFieldsTest extends TestCase
{
    public function test_blank_and_non_string_descriptions_become_empty(): void
    {
        self::assertSame('', CatalogFields::description(null));
        self::assertSame('', CatalogFields::description(12));
        self::assertSame('', CatalogFields::description('   '));
    }

    public function test_short_descriptions_are_preserved(): void
    {
        self::assertSame('A compact summary.', CatalogFields::description('  A compact summary.  '));
    }

    public function test_long_descriptions_stop_at_the_table_display_limit(): void
    {
        $value = str_repeat('あ', CatalogFields::DESCRIPTION_MAX_LENGTH + 20);

        $limited = CatalogFields::description($value);

        self::assertSame(CatalogFields::DESCRIPTION_MAX_LENGTH, mb_strlen($limited));
        self::assertSame(mb_substr($value, 0, CatalogFields::DESCRIPTION_MAX_LENGTH), $limited);
    }
}
