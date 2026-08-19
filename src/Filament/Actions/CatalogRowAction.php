<?php

namespace Kazaminosuke\ModManager\Filament\Actions;

use Filament\Actions\Action;
use Kazaminosuke\ModManager\Support\CatalogRowActionMarkup;

/**
 * Catalog table icon actions without Filament's per-row SVG/tooltip payload.
 * Authorize(), modal(), and action() keep their existing Filament behaviour.
 */
final class CatalogRowAction extends Action
{
    public static function compact(string $name, string $color): static
    {
        return self::make($name)
            ->iconButton()
            ->color($color)
            ->extraAttributes([
                'data-mmr-swr-row-action' => $name,
                'data-mmr-swr-row-action-color' => $color,
            ]);
    }

    protected function toIconButtonHtml(): string
    {
        $label = trim(strip_tags((string) ($this->getTooltip() ?? $this->getLabel() ?? $this->getName())));
        $color = $this->getExtraAttributes()['data-mmr-swr-row-action-color']
            ?? (is_string($this->getColor()) ? $this->getColor() : 'gray');
        $name = $this->getExtraAttributes()['data-mmr-swr-row-action'] ?? $this->getName();

        return CatalogRowActionMarkup::button([
            'name' => $name,
            'color' => $color,
            'label' => $label,
            'disabled' => $this->isDisabled(),
            'wireClick' => $this->isDisabled() ? null : $this->getLivewireClickHandler(),
            'wireKey' => $this->getLivewireKey(),
        ]);
    }
}
