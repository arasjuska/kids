<?php

namespace App\Support;

use App\Data\GeocodeResult;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

final class GeoNormalizer
{
    public function normalizeForwardQuery(string $query, ?string $countryCode = null): array
    {
        $normalizedQuery = Str::of($query)
            ->trim()
            ->replaceMatches('/\s+/', ' ')
            ->lower()
            ->toString();

        return [
            'q' => $normalizedQuery,
            'cc' => $countryCode ? Str::upper(Str::substr($countryCode, 0, 2)) : null,
        ];
    }

    public function roundForReverse(float $lat, float $lon, string $accuracy): array
    {
        $precision = config('geocoding.rounding.reverse.' . Str::upper($accuracy), config('geocoding.rounding.reverse.default'));

        return [
            'lat' => round($lat, $precision),
            'lon' => round($lon, $precision),
        ];
    }

    public function mapProviderPayload(array $raw): GeocodeResult
    {
        $placeId = (string) Arr::get($raw, 'place_id', Str::uuid()->toString());
        $formatted = Str::of((string) Arr::get($raw, 'display_name', Arr::get($raw, 'formatted')))->trim()->toString();
        $short = Str::of((string) Arr::get($raw, 'short_address', $formatted))->trim()->toString();
        $city = Arr::get($raw, 'address.city') ?? Arr::get($raw, 'address.town') ?? Arr::get($raw, 'city');
        $city = $city ? Str::title(Str::of($city)->trim()) : null;
        $countryCode = Arr::get($raw, 'address.country_code', Arr::get($raw, 'country_code'));
        $countryCode = $countryCode ? Str::upper(Str::substr($countryCode, 0, 2)) : null;
        $accuracy = Str::upper((string) Arr::get($raw, 'accuracy', Arr::get($raw, 'accuracy_level', 'UNKNOWN')));
        $confidence = (float) min(1.0, max(0.0, Arr::get($raw, 'confidence', Arr::get($raw, 'confidence_score', 0.0))));

        return new GeocodeResult(
            placeId: $placeId,
            shortAddressLine: $short,
            formattedAddress: $formatted,
            city: $city,
            countryCode: $countryCode,
            latitude: (float) Arr::get($raw, 'lat', Arr::get($raw, 'latitude', 0.0)),
            longitude: (float) Arr::get($raw, 'lon', Arr::get($raw, 'longitude', 0.0)),
            accuracyLevel: $accuracy,
            providerConfidence: $confidence,
            meta: Arr::only($raw, ['address', 'boundingbox', 'licence', 'raw']),
        );
    }
}
