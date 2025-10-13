<?php

namespace App\Filament\Forms\Components;

use Filament\Forms\Components\Field;

class LeafletMap extends Field
{
    protected string $view = 'filament.forms.components.leaflet-map';

    protected array $defaultLocation = [54.8985, 23.9036]; // Kaunas
    protected int $zoom = 13;

    public function defaultLocation(float $lat, float $lng): static
    {
        $this->defaultLocation = [$lat, $lng];
        return $this;
    }

    public function zoom(int $zoom): static
    {
        $this->zoom = $zoom;
        return $this;
    }

    public function getDefaultLocation(): array
    {
        return $this->defaultLocation;
    }

    public function getZoom(): int
    {
        return $this->zoom;
    }
}
