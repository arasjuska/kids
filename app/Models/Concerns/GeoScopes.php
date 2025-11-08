<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;

trait GeoScopes
{
    /**
     * scopeNearby: bbox (MBR) → exact great-circle distance filter → order by distance ASC.
     *
     * @param  float|int  $meters
     * @param  array<string>  $columns
     */
    public function scopeNearby(
        Builder $query,
        float $lat,
        float $lon,
        float $meters,
        array $columns = ['id', 'formatted_address', 'latitude', 'longitude']
    ): Builder {
        $lat = (float) $lat;
        $lon = (float) $lon;
        $meters = (float) max(0, $meters);

        $degPerMeterLat = 1 / 111_320;
        $degPerMeterLon = 1 / (111_320 * max(0.000001, cos(deg2rad($lat))));

        $dLat = $meters * $degPerMeterLat;
        $dLon = $meters * $degPerMeterLon;

        $minLat = $lat - $dLat;
        $maxLat = $lat + $dLat;
        $minLon = $lon - $dLon;
        $maxLon = $lon + $dLon;

        $polyWkt = sprintf(
            'POLYGON((%F %F,%F %F,%F %F,%F %F,%F %F))',
            $minLon, $minLat,
            $minLon, $maxLat,
            $maxLon, $maxLat,
            $maxLon, $minLat,
            $minLon, $minLat
        );

        $select = array_values(array_unique(array_merge($columns, ['id'])));

        return $query
            ->select($select)
            ->selectRaw(
                'ST_Distance_Sphere(`location`, ST_SRID(POINT(?, ?), 4326)) AS distance',
                [$lon, $lat]
            )
            ->whereRaw(
                'MBRContains(ST_SRID(ST_GeomFromText(?), 4326), `location`)',
                [$polyWkt]
            )
            ->whereRaw(
                'ST_Distance_Sphere(`location`, ST_SRID(POINT(?, ?), 4326)) <= ?',
                [$lon, $lat, $meters]
            )
            ->orderBy('distance', 'asc');
    }

    /**
     * scopeWithinBounds: fast MBR polygon containment only.
     *
     * @param  array<string>  $columns
     */
    public function scopeWithinBounds(
        Builder $query,
        float $minLat,
        float $minLon,
        float $maxLat,
        float $maxLon,
        array $columns = ['id', 'formatted_address', 'latitude', 'longitude']
    ): Builder {
        $polyWkt = sprintf(
            'POLYGON((%F %F,%F %F,%F %F,%F %F,%F %F))',
            $minLon, $minLat,
            $minLon, $maxLat,
            $maxLon, $maxLat,
            $maxLon, $minLat,
            $minLon, $minLat
        );

        $select = array_values(array_unique(array_merge($columns, ['id'])));

        return $query
            ->select($select)
            ->whereRaw(
                'MBRContains(ST_SRID(ST_GeomFromText(?), 4326), `location`)',
                [$polyWkt]
            );
    }
}
