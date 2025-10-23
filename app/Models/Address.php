<?php

namespace App\Models;

use App\Enums\AddressTypeEnum;
use App\Enums\AccuracyLevelEnum;
use App\Observers\AddressObserver;
use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\GeoScopes;

class Address extends Model
{
    use GeoScopes;

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
        'provider',
        'provider_place_id',
        'osm_type',
        'osm_id',
        'address_signature',
        'requires_verification',
    ];

    protected $casts = [
        'latitude' => 'float',
        'longitude' => 'float',
        'confidence_score' => 'float',
        'address_type' => AddressTypeEnum::class,
        'accuracy_level' => AccuracyLevelEnum::class,
        'is_virtual' => 'boolean',
        'raw_api_response' => 'array',
        'osm_id' => 'integer',
        'quality_tier' => 'string',
        'verified_at' => 'datetime',
        'fields_refreshed_at' => 'datetime',
        'manually_overridden' => 'boolean',
        'source_locked' => 'boolean',
        'requires_verification' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::observe(AddressObserver::class);
    }

    public function places()
    {
        return $this->hasMany(Place::class);
    }
}
