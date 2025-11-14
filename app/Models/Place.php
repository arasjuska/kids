<?php

namespace App\Models;

use App\Http\Requests\AddressRequest;
use App\Support\TextNormalizer;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

class Place extends Model
{
    protected $fillable = [
        'address_id',
        'name',
    ];

    protected function name(): Attribute
    {
        return Attribute::make(
            set: static fn ($value) => is_string($value) || $value === null
                ? TextNormalizer::toNfc($value)
                : TextNormalizer::toNfc((string) $value)
        );
    }

    public function address()
    {
        return $this->belongsTo(Address::class);
    }

    public function saveAddress(array $addressData): void
    {
        $payload = $this->prepareAddressPayload($addressData);

        if (config('app.debug')) {
            Log::debug('Place: saving address', [
                'place_id' => $this->getKey(),
                'address_id' => $this->address_id,
                'payload_preview' => Arr::only($payload, [
                    'formatted_address',
                    'latitude',
                    'longitude',
                    'address_type',
                ]),
            ]);
        }

        $address = $this->address ?? $this->address()->first();

        if ($address) {
            $address->fill($payload);
            $address->save();
        } else {
            $address = Address::create($payload);
            $this->address()->associate($address);
            $this->saveQuietly();
        }

        if (config('app.debug')) {
            Log::debug('Place: address saved', [
                'place_id' => $this->getKey(),
                'address_id' => $address->getKey(),
            ]);
        }
    }

    protected function prepareAddressPayload(array $addressData): array
    {
        $normalized = AddressRequest::normalizePayload($addressData);

        $latitude = $normalized['latitude'] ?? null;
        $longitude = $normalized['longitude'] ?? null;

        return [
            'formatted_address' => $normalized['formatted_address'] ?? '',
            'short_address_line' => $normalized['short_address_line'] ?? null,
            'street_name' => $normalized['street_name'] ?? null,
            'street_number' => $normalized['street_number'] ?? null,
            'city' => $normalized['city'] ?? null,
            'state' => $normalized['state'] ?? null,
            'postal_code' => $normalized['postal_code'] ?? null,
            'country' => $normalized['country'] ?? 'Lietuva',
            'country_code' => strtoupper($normalized['country_code'] ?? 'LT'),
            'latitude' => is_numeric($latitude) ? round((float) $latitude, 6) : 0.0,
            'longitude' => is_numeric($longitude) ? round((float) $longitude, 6) : 0.0,
            'address_type' => $normalized['address_type'] ?? 'unverified',
            'confidence_score' => $normalized['confidence_score'] ?? null,
            'description' => $normalized['description'] ?? null,
            'raw_api_response' => $normalized['raw_api_response'] ?? null,
            'is_virtual' => ($normalized['address_type'] ?? null) === 'virtual',
            'provider' => $normalized['provider'] ?? 'nominatim',
            'provider_place_id' => $normalized['provider_place_id'] ?? null,
            'osm_type' => $normalized['osm_type'] ?? null,
            'osm_id' => isset($normalized['osm_id']) ? (int) $normalized['osm_id'] : null,
            'address_signature' => $normalized['address_signature'] ?? null,
            'source_locked' => (bool) ($normalized['source_locked'] ?? false),
            'override_reason' => $normalized['override_reason'] ?? null,
        ];
    }
}
