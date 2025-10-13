<?php

namespace App\Models;

use App\Enums\AddressTypeEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

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
    ];

    protected $casts = [
        'latitude' => 'float',
        'longitude' => 'float',
        'confidence_score' => 'float',
        'address_type' => AddressTypeEnum::class,
        'is_virtual' => 'boolean',
        'raw_api_response' => 'array',
    ];

    /**
     * Paieškos apimtis (Scope), leidžianti filtruoti adresus spinduliu (km).
     * Naudoja Bounding Box (WHERE BETWEEN ant indeksuotų stulpelių) optimizaciją.
     */
    public function scopeNearby(Builder $query, float $latitude, float $longitude, float $radiusInKm = 2.0): Builder
    {
        $centerLat = $latitude;
        $centerLng = $longitude;
        $radiusKm = $radiusInKm;

        // Apytiksliai laipsniai kilometrui. Naudojamos šiek tiek didesnės vertės, kad būtų išvengta klaidų.
        $latDelta = $radiusKm * 0.0091; // ~1km = 0.0091 laipsnio (Latitude)
        $lngDelta = $radiusKm * 0.0125; // ~1km = 0.0125 laipsnio (Longitude)

        $minLat = $centerLat - $latDelta;
        $maxLat = $centerLat + $latDelta;
        $minLng = $centerLng - $lngDelta;
        $maxLng = $centerLng + $lngDelta;

        // 1. GREITAS FILTRAVIMAS (Bounding Box)
        // Šis filtras naudoja INDEKSUS (latitude ir longitude) ir eliminuoja daugumą 50K įrašų.
        $query->whereBetween('latitude', [$minLat, $maxLat])
            ->whereBetween('longitude', [$minLng, $maxLng]);

        // 2. TIKSLUS SKAIČIAVIMAS (Haversine formulė)
        // Vykdoma TIK ant nedidelio atrinkto įrašų pogrupio.
        $earthRadius = 6371; // Žemės spindulys (km)

        $haversine = "(
            $earthRadius * acos(
                cos(radians(?)) * cos(radians(latitude)) * cos(radians(longitude) - radians(?)) + sin(radians(?)) * sin(radians(latitude))
            )
        )";

        // Pridedamas atstumo stulpelis, reikšmės automatiškai priskiriamos '?' žymekliams
        $query->selectRaw(DB::raw("*, {$haversine} AS distance"), [$latitude, $longitude, $latitude]);

        // 3. PASKUTINIS FILTRAVIMAS IR RŪŠIAVIMAS
        // Atmetami tie įrašai, kurie pateko į kvadratą, bet yra toliau nei spindulys.
        return $query->having('distance', '<=', $radiusInKm)
            ->orderBy('distance', 'asc');
    }

    public function places()
    {
        return $this->hasMany(Place::class);
    }
}
