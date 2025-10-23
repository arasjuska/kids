<?php

namespace App\Data;

final class GeocodeResult
{
    public function __construct(
        public readonly string $placeId,
        public readonly string $shortAddressLine,
        public readonly string $formattedAddress,
        public readonly ?string $city,
        public readonly ?string $countryCode,
        public readonly float $latitude,
        public readonly float $longitude,
        public readonly string $accuracyLevel,
        public readonly float $providerConfidence,
        public readonly array $meta = [],
    ) {
    }

    public function toArray(): array
    {
        return [
            'place_id' => $this->placeId,
            'short_address_line' => $this->shortAddressLine,
            'formatted_address' => $this->formattedAddress,
            'city' => $this->city,
            'country_code' => $this->countryCode,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'accuracy_level' => $this->accuracyLevel,
            'provider_confidence' => $this->providerConfidence,
            'confidence' => $this->providerConfidence,
            'confidence_score' => $this->providerConfidence,
            'meta' => $this->meta,
        ];
    }
}
