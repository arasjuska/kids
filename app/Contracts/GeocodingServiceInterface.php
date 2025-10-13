<?php

namespace App\Contracts;

use Illuminate\Support\Collection;

interface GeocodingServiceInterface
{
    /**
     * Search for addresses by query
     */
    public function search(string $query, array $options = []): Collection;

    /**
     * Reverse geocoding - get address by coordinates
     */
    public function reverse(float $lat, float $lng): ?object;

    /**
     * Check if service is available
     */
    public function isAvailable(): bool;

    /**
     * Get service name/identifier
     */
    public function getProviderName(): string;
}
