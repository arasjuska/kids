<?php

namespace App\Models;

use App\Enums\AddressTypeEnum;
use App\Enums\AccuracyLevelEnum;
use App\Observers\AddressObserver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Expression;

class Address extends Model
{
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
        'quality_tier' => 'integer',
        'verified_at' => 'datetime',
        'fields_refreshed_at' => 'datetime',
        'manually_overridden' => 'boolean',
        'source_locked' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::observe(AddressObserver::class);
    }

    /**
     * Paieškos apimtis (Scope), leidžianti filtruoti adresus spinduliu (km).
     * Naudoja Bounding Box (WHERE BETWEEN ant indeksuotų stulpelių) optimizaciją.
     */
    public function scopeNearby(Builder $query, float $latitude, float $longitude, int $meters = 1000): Builder
    {
        $meters = max(0, $meters);
        $table = $query->getModel()->getTable();

        $latDelta = $meters / 111320;
        $cosLat = cos(deg2rad($latitude));
        $cosLat = max(abs($cosLat), 1e-8);
        $lonDelta = $meters / (111320 * $cosLat);

        $minLat = max(-90.0, $latitude - $latDelta);
        $maxLat = min(90.0, $latitude + $latDelta);
        $minLon = max(-180.0, $longitude - $lonDelta);
        $maxLon = min(180.0, $longitude + $lonDelta);

        $polygonWkt = sprintf(
            'POLYGON((%1$.8F %2$.8F, %1$.8F %3$.8F, %4$.8F %3$.8F, %4$.8F %2$.8F, %1$.8F %2$.8F))',
            $minLon,
            $minLat,
            $maxLat,
            $maxLon
        );

        $longitudeSql = sprintf('%.8F', $longitude);
        $latitudeSql = sprintf('%.8F', $latitude);
        $distanceExpression = sprintf(
            'ST_Distance_Sphere(location, ST_SRID(POINT(%s, %s), 4326))',
            $longitudeSql,
            $latitudeSql
        );

        if (empty($query->getQuery()->columns)) {
            $query->select("{$table}.*");
        }

        $query->addSelect(new Expression("{$distanceExpression} AS distance"));

        return $query
            ->whereRaw('MBRContains(ST_GeomFromText(?, 4326), location)', [$polygonWkt])
            ->whereRaw("{$distanceExpression} <= ?", [$meters])
            ->orderBy('distance');
    }

    public function scopeWithinBounds(Builder $query, float $minLatitude, float $minLongitude, float $maxLatitude, float $maxLongitude): Builder
    {
        if ($minLatitude > $maxLatitude) {
            [$minLatitude, $maxLatitude] = [$maxLatitude, $minLatitude];
        }

        if ($minLongitude > $maxLongitude) {
            [$minLongitude, $maxLongitude] = [$maxLongitude, $minLongitude];
        }

        $minLatitude = max(-90.0, $minLatitude);
        $maxLatitude = min(90.0, $maxLatitude);
        $minLongitude = max(-180.0, $minLongitude);
        $maxLongitude = min(180.0, $maxLongitude);

        $polygonWkt = sprintf(
            'POLYGON((%1$.8F %2$.8F, %1$.8F %4$.8F, %3$.8F %4$.8F, %3$.8F %2$.8F, %1$.8F %2$.8F))',
            $minLongitude,
            $minLatitude,
            $maxLongitude,
            $maxLatitude
        );

        return $query->whereRaw('MBRContains(ST_GeomFromText(?, 4326), location)', [$polygonWkt]);
    }

    public function places()
    {
        return $this->hasMany(Place::class);
    }
}
