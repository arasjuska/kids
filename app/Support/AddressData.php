<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Address;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Canonical representation of an address snapshot inside the application.
 * Centralizes the confirmed invariant (lat + lng + snapshot timestamp)
 * and the optional metadata derived from geocoding.
 */
final class AddressData implements Arrayable
{
    public function __construct(
        public ?float $latitude = null,
        public ?float $longitude = null,
        public ?CarbonInterface $snapshotAt = null,
        public ?string $formattedAddress = null,
        public ?string $shortAddressLine = null,
        public ?string $city = null,
        public ?string $streetName = null,
        public ?string $streetNumber = null,
        public ?string $postalCode = null,
        public ?string $state = null,
        public ?string $country = null,
        public ?string $countryCode = null,
        public ?string $quality = null,
        public ?string $precision = null,
        public ?string $provider = null,
        public ?array $providerPayload = null,
        public ?string $addressSignature = null,
        public ?string $inputMode = null,
    ) {}

    /**
     * Build a DTO from an arbitrary payload (e.g. Livewire form state).
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            latitude: self::nullableFloat($data['latitude'] ?? Arr::get($data, 'coordinates.latitude')),
            longitude: self::nullableFloat($data['longitude'] ?? Arr::get($data, 'coordinates.longitude')),
            snapshotAt: self::nullableCarbon(
                $data['snapshot_at']
                    ?? $data['fields_refreshed_at']
                    ?? $data['verified_at']
                    ?? null
            ),
            formattedAddress: self::nullableString($data['formatted_address'] ?? null),
            shortAddressLine: self::nullableString($data['short_address_line'] ?? null),
            city: self::nullableString($data['city'] ?? null),
            streetName: self::nullableString($data['street_name'] ?? null),
            streetNumber: self::nullableString($data['street_number'] ?? null),
            postalCode: self::nullableString($data['postal_code'] ?? null),
            state: self::nullableString($data['state'] ?? null),
            country: self::nullableString($data['country'] ?? null),
            countryCode: self::nullableString($data['country_code'] ?? null),
            quality: self::nullableString($data['quality'] ?? $data['quality_tier'] ?? null),
            precision: self::nullableString($data['precision'] ?? $data['accuracy_level'] ?? null),
            provider: self::nullableString($data['provider'] ?? null),
            providerPayload: self::nullableArray($data['provider_payload'] ?? $data['raw_api_response'] ?? null),
            addressSignature: self::nullableBinary($data['address_signature'] ?? null),
            inputMode: self::nullableString($data['input_mode'] ?? null),
        );
    }

    public static function fromModel(Address $address): self
    {
        return new self(
            latitude: $address->latitude,
            longitude: $address->longitude,
            snapshotAt: $address->snapshotTimestamp(),
            formattedAddress: $address->formatted_address,
            shortAddressLine: $address->short_address_line,
            city: $address->city,
            streetName: $address->street_name,
            streetNumber: $address->street_number,
            postalCode: $address->postal_code,
            state: $address->state,
            country: $address->country,
            countryCode: $address->country_code,
            quality: $address->quality_tier,
            precision: $address->accuracy_level instanceof \BackedEnum
                ? $address->accuracy_level->value
                : ($address->accuracy_level ?: null),
            provider: $address->provider,
            providerPayload: is_array($address->raw_api_response) ? $address->raw_api_response : null,
            addressSignature: $address->address_signature,
            inputMode: null,
        );
    }

    public function hasCoordinates(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }

    public function isConfirmed(): bool
    {
        return $this->hasCoordinates() && $this->snapshotAt !== null;
    }

    public function ensureSnapshot(?CarbonInterface $fallback): self
    {
        if ($this->snapshotAt === null && $fallback !== null) {
            $this->snapshotAt = $fallback;
        }

        return $this;
    }

    /**
     * Convert to an array keyed similarly to the underlying Address model.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'snapshot_at' => $this->snapshotAt,
            'formatted_address' => $this->formattedAddress,
            'short_address_line' => $this->shortAddressLine,
            'street_name' => $this->streetName,
            'street_number' => $this->streetNumber,
            'city' => $this->city,
            'state' => $this->state,
            'postal_code' => $this->postalCode,
            'country' => $this->country,
            'country_code' => $this->countryCode,
            'quality' => $this->quality,
            'precision' => $this->precision,
            'provider' => $this->provider,
            'provider_payload' => $this->providerPayload,
            'address_signature' => $this->addressSignature,
            'input_mode' => $this->inputMode,
        ];
    }

    /**
     * Map canonical fields to the Address model persistence shape.
     *
     * @return array<string, mixed>
     */
    public function toPersistenceAttributes(): array
    {
        return array_filter([
            'formatted_address' => $this->formattedAddress ?? '',
            'short_address_line' => $this->shortAddressLine,
            'street_name' => $this->streetName,
            'street_number' => $this->streetNumber,
            'city' => $this->city,
            'state' => $this->state,
            'postal_code' => $this->postalCode,
            'country' => $this->country ?? 'Lietuva',
            'country_code' => Str::upper($this->countryCode ?? 'LT'),
            'latitude' => $this->latitude !== null ? round($this->latitude, 6) : null,
            'longitude' => $this->longitude !== null ? round($this->longitude, 6) : null,
            'fields_refreshed_at' => $this->snapshotAt,
            'quality_tier' => $this->quality,
            'accuracy_level' => $this->precision,
            'provider' => $this->provider,
            'raw_api_response' => $this->providerPayload,
            'address_signature' => $this->addressSignature,
        ], static fn ($value) => $value !== null);
    }

    private static function nullableFloat(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value) && trim($value) === '') {
            return null;
        }

        return is_numeric($value) ? (float) $value : null;
    }

    private static function nullableCarbon(mixed $value): ?CarbonInterface
    {
        if ($value instanceof CarbonInterface) {
            return $value;
        }

        if ($value === null || $value === '') {
            return null;
        }

        return Carbon::make($value);
    }

    private static function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $string = is_string($value) ? $value : (string) $value;
        $trimmed = trim($string);

        return $trimmed === '' ? null : $trimmed;
    }

    private static function nullableArray(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }

        if (is_array($value)) {
            return $value;
        }

        return null;
    }

    private static function nullableBinary(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return is_string($value) ? $value : null;
    }
}
