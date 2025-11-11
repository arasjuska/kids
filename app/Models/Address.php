<?php

namespace App\Models;

use App\Casts\SafeJson;
use App\Enums\AccuracyLevelEnum;
use App\Enums\AddressTypeEnum;
use App\Models\Concerns\GeoScopes;
use App\Observers\AddressObserver;
use App\Support\TextNormalizer;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Casts\Attribute;

class Address extends Model
{
    use GeoScopes;
    use HasFactory;

    protected $fillable = [
        'formatted_address',
        'short_address_line',
        'street_name',
        'street_number',
        'city',
        'state',
        'postal_code',
        'country',
        'country_code',
        'latitude',
        'longitude',
        'address_type',
        'confidence_score',
        'description',
        'raw_api_response',
        'is_virtual',
        'geocoding_provider',
        'accuracy_level',
        'quality_tier',
        'verified_at',
        'fields_refreshed_at',
        'manually_overridden',
        'source_locked',
        'requires_verification',
        'override_reason',
        'provider',
        'provider_place_id',
        'osm_type',
        'osm_id',
        'address_signature',
    ];

    protected $casts = [
        'latitude' => 'float',
        'longitude' => 'float',
        'confidence_score' => 'float',
        'address_type' => AddressTypeEnum::class,
        'accuracy_level' => AccuracyLevelEnum::class,
        'is_virtual' => 'boolean',
        'raw_api_response' => SafeJson::class,
        'osm_id' => 'integer',
        'quality_tier' => 'string',
        'verified_at' => 'datetime',
        'fields_refreshed_at' => 'datetime',
        'manually_overridden' => 'boolean',
        'source_locked' => 'boolean',
        'requires_verification' => 'boolean',
        'override_reason' => 'string',
    ];

    protected static function booted(): void
    {
        static::observe(AddressObserver::class);
    }

    protected function formattedAddress(): Attribute
    {
        return $this->normalizedStringAttribute();
    }

    protected function shortAddressLine(): Attribute
    {
        return $this->normalizedStringAttribute();
    }

    protected function streetName(): Attribute
    {
        return $this->normalizedStringAttribute();
    }

    protected function streetNumber(): Attribute
    {
        return $this->normalizedStringAttribute();
    }

    protected function city(): Attribute
    {
        return $this->normalizedStringAttribute();
    }

    protected function state(): Attribute
    {
        return $this->normalizedStringAttribute();
    }

    protected function postalCode(): Attribute
    {
        return $this->normalizedStringAttribute();
    }

    protected function country(): Attribute
    {
        return $this->normalizedStringAttribute();
    }

    protected function countryCode(): Attribute
    {
        return $this->normalizedStringAttribute();
    }

    protected function description(): Attribute
    {
        return $this->normalizedStringAttribute();
    }

    protected function overrideReason(): Attribute
    {
        return $this->normalizedStringAttribute();
    }

    protected function provider(): Attribute
    {
        return $this->normalizedStringAttribute();
    }

    protected function providerPlaceId(): Attribute
    {
        return $this->normalizedStringAttribute();
    }

    protected function osmType(): Attribute
    {
        return $this->normalizedStringAttribute();
    }

    private function normalizedStringAttribute(): Attribute
    {
        return Attribute::make(
            set: static function ($value) {
                if ($value === null) {
                    return null;
                }

                if (! is_string($value)) {
                    $value = (string) $value;
                }

                return TextNormalizer::toNfc($value);
            },
        );
    }

    public function places()
    {
        return $this->hasMany(Place::class);
    }

    public function audits()
    {
        return $this->hasMany(AddressAudit::class);
    }
}
