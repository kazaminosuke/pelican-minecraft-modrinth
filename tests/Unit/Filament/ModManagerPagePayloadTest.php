<?php

namespace Kazaminosuke\ModManager\Tests\Unit\Filament;

use Kazaminosuke\ModManager\Filament\Server\Pages\ModManagerPage;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

class ModManagerPagePayloadTest extends TestCase
{
    public function test_unknown_files_are_not_part_of_the_livewire_snapshot(): void
    {
        $property = new ReflectionProperty(ModManagerPage::class, 'unknownFiles');

        self::assertTrue($property->isProtected());
        self::assertFalse($property->isPublic());
    }
}
