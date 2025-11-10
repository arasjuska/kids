<?php

namespace App\Support;

use App\Data\GeocodeResult;
use App\Support\AddressNormalizer;
use App\Support\NominatimResultMapper;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

final class GeoNormalizer
{
    public function normalizeForwardQuery(string $query, ?string $countryCode = null, bool $includeRaw = false): array
    {
        $raw = Str::of($query)
            ->trim()
            ->replaceMatches('/\s+/', ' ')
            ->toString();

        $canonical = Str::of($raw)
            ->lower()
            ->ascii()
            ->toString();

        $payload = [
            'q' => $canonical,
            'cc' => $countryCode ? Str::upper(Str::substr($countryCode, 0, 2)) : null,
        ];

        if ($includeRaw) {
            $payload['raw'] = $raw;
            $payload['key'] = $canonical;
        }

        return $payload;
    }

    public function roundForReverse(float $lat, float $lon, string $accuracy): array
    {
        $precision = config('geocoding.rounding.reverse.'.Str::upper($accuracy), config('geocoding.rounding.reverse.default'));

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

        $mappedAddress = NominatimResultMapper::toAddressArray($raw);
        $city = $mappedAddress['city'] ?? null;
        $countryCodeRaw = $mappedAddress['country_code'] ?? Arr::get($raw, 'address.country_code', Arr::get($raw, 'country_code'));
        $countryCode = $countryCodeRaw ? Str::upper(Str::substr($countryCodeRaw, 0, 2)) : null;
        $accuracy = Str::upper((string) Arr::get($raw, 'accuracy', Arr::get($raw, 'accuracy_level', 'UNKNOWN')));
        $confidenceValue = Arr::get($raw, 'confidence', Arr::get($raw, 'confidence_score'));
        $baselineConfidence = $this->inferConfidence($raw, $accuracy);

        if ($confidenceValue === null) {
            $confidence = $baselineConfidence;
        } else {
            $confidence = max((float) $confidenceValue, $baselineConfidence);
        }

        $confidence = min(1.0, max(0.0, $confidence));

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

    /**
     * Map provider payload into the canonical suggestion schema.
     *
     * Ensures keys: street, house_number, city, region, postcode, country, country_code (lowercase), and
     * produces an address_signature that is a 64-character lowercase hex string when sufficient data exists.
     *
     * @return array<string, mixed>
     */
    public function mapProviderSuggestion(array $raw): array
    {
        $dto = $this->mapProviderPayload($raw);
        $address = Arr::get($raw, 'address', []);
        $mappedAddress = NominatimResultMapper::toAddressArray($raw);

        $street = $mappedAddress['street'] ?? Arr::get($address, 'road');
        $houseNumber = $mappedAddress['house_number'] ?? Arr::get($address, 'house_number');
        $city = $mappedAddress['city'] ?? $dto->city;
        $region = $mappedAddress['region'] ?? Arr::get($address, 'state');
        $postcode = $mappedAddress['postcode'] ?? Arr::get($address, 'postcode');
        $country = $mappedAddress['country'] ?? Arr::get($address, 'country');
        $countryCode = $mappedAddress['country_code'] ?? $dto->countryCode;
        $countryCode = $countryCode ? Str::lower($countryCode) : null;

        $result = [
            'place_id' => $dto->placeId,
            'short_address_line' => $dto->shortAddressLine,
            'context_line' => Arr::get($raw, 'display_name', Arr::get($address, 'city')),
            'formatted_address' => $dto->formattedAddress,
            'latitude' => $dto->latitude,
            'longitude' => $dto->longitude,
            'street' => $street,
            'house_number' => $houseNumber,
            'city' => $city,
            'region' => $region,
            'postcode' => $postcode,
            'country' => $country,
            'country_code' => $countryCode,
            'confidence' => $dto->providerConfidence,
            'provider' => Str::lower((string) Arr::get($raw, 'provider', 'nominatim')),
            'osm_type' => Arr::get($raw, 'osm_type'),
            'osm_id' => Arr::get($raw, 'osm_id'),
            'raw_payload' => $this->sanitizeRawPayload($raw),
        ];

        $signature = app(AddressNormalizer::class)->signature([
            'street_name' => $street,
            'street_number' => $houseNumber,
            'city' => $city,
            'country_code' => $countryCode,
            'postal_code' => $postcode,
        ]);

        if ($signature !== null) {
            $hexSignature = strtolower(bin2hex($signature));
            $result['address_signature'] = $hexSignature;
        }

        return array_merge([
            'street' => null,
            'house_number' => null,
            'city' => null,
            'region' => null,
            'postcode' => null,
            'country' => null,
            'country_code' => null,
        ], $result);
    }

    private function sanitizeRawPayload(array $raw): array
    {
        $allowed = [
            'place_id',
            'display_name',
            'lat',
            'lon',
            'address',
            'osm_type',
            'osm_id',
            'boundingbox',
            'class',
            'type',
        ];

        return Arr::only($raw, $allowed);
    }

    private function inferConfidence(array $raw, string $accuracy): float
    {
        $address = Arr::get($raw, 'address', []);

        $baseline = match (Str::upper($accuracy)) {
            'ROOFTOP' => 0.95,
            'RANGE_INTERPOLATED' => 0.80,
            'GEOMETRIC_CENTER' => 0.60,
            'APPROXIMATE' => 0.40,
            default => 0.50,
        };

        if (! empty($address['house_number']) && ! empty($address['road'])) {
            return max($baseline, 0.95);
        }

        if (! empty($address['road'])) {
            return max($baseline, 0.80);
        }

        if (! empty($address['city'])) {
            return max($baseline, 0.60);
        }

        return $baseline;
    }
}
