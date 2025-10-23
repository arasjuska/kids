<?php

namespace App\Contracts;

use App\Data\GeocodeResult;
use Illuminate\Support\Collection;

interface GeocodingServiceInterface
{
    public function forward(string $query, ?string $countryCode = null): ?GeocodeResult;

    public function reverse(float $lat, float $lon): ?GeocodeResult;

    /**
     * @param  array<string, mixed>  $options
     * @return Collection<int, array<string, mixed>>
     */
    public function search(string $query, array $options = []): Collection;
}
