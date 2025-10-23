<?php

namespace App\Contracts;

use App\Data\GeocodeResult;

interface GeocodingServiceInterface
{
    public function forward(string $query, ?string $countryCode = null): ?GeocodeResult;

    public function reverse(float $lat, float $lon): ?GeocodeResult;
}
