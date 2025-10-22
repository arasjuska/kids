<?php

namespace App\Models;

use App\Enums\AddressTypeEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Str;

class Address extends Model
{
    protected $fillable = [
        'formatted_address',
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
        'is_virtual' => 'boolean',
        'raw_api_response' => 'array',
        'osm_id' => 'integer',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $address): void {
            $address->address_signature = self::makeAddressSignature(
                $address->street_name,
                $address->street_number,
                $address->city,
                $address->country_code,
                $address->postal_code
            );
        });
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

    private static function makeAddressSignature(
        ?string $streetName,
        ?string $streetNumber,
        ?string $city,
        ?string $countryCode,
        ?string $postalCode
    ): ?string {
        $parts = [
            self::normalizeSignaturePart($streetName),
            self::normalizeSignaturePart($streetNumber),
            self::normalizeSignaturePart($city),
            self::normalizeSignaturePart($countryCode, true),
            self::normalizeSignaturePart($postalCode, false, false),
        ];

        $signatureString = implode('|', $parts);

        if ($signatureString === '||||') {
            return null;
        }

        return hash('sha256', $signatureString, true);
    }

    private static function normalizeSignaturePart(
        ?string $value,
        bool $uppercase = false,
        bool $lowercase = true
    ): string {
        $value = trim((string) ($value ?? ''));

        if ($value === '') {
            return '';
        }

        if ($uppercase) {
            return Str::upper($value);
        }

        return $lowercase ? Str::lower($value) : $value;
    }
}
