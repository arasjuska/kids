<?php

namespace App\Support;

final class PrecisionFromZoom
{
    /** @var array<int, float> */
    private static array $cache = [];
    private static bool $booted = false;
    private static int $minZoom = 0;
    private static int $maxZoom = 0;
    /** @var callable|null */
    private static $meterObserver = null;

    public static function meters(int $zoom): float
    {
        if (! self::$booted) {
            self::boot();
        }

        if ($zoom < self::$minZoom) {
            $zoom = self::$minZoom;
        } elseif ($zoom > self::$maxZoom) {
            $zoom = self::$maxZoom;
        }

        $value = self::$cache[$zoom];

        if (self::$meterObserver !== null) {
            (self::$meterObserver)($zoom);
        }

        return $value;
    }

    public static function refresh(): void
    {
        self::$booted = false;
        self::$cache = [];
        self::boot();
    }

    private static function boot(): void
    {
        if (self::$booted) {
            return;
        }

        $defined = (array) config('map_clusters.precision_by_zoom', []);

        if ($defined === []) {
            $defined = (array) config('map_clusters.zoom_precision', []);
        }

        self::$minZoom = (int) config('map_clusters.zoom_min', 1);
        self::$maxZoom = (int) config('map_clusters.zoom_max', 18);

        if (self::$maxZoom < self::$minZoom) {
            self::$maxZoom = self::$minZoom;
        }

        $default = (float) config('map_clusters.precision_default', 0.50);
        $minPrecision = (float) config('map_clusters.precision_min', 0.005);
        $maxPrecision = (float) config('map_clusters.precision_max', 2.0);

        $last = array_key_exists(self::$minZoom, $defined)
            ? (float) $defined[self::$minZoom]
            : $default;

        for ($z = self::$minZoom; $z <= self::$maxZoom; $z++) {
            if (array_key_exists($z, $defined)) {
                $last = (float) $defined[$z];
            }

            self::$cache[$z] = $last;
        }

        for ($z = self::$minZoom + 1; $z <= self::$maxZoom; $z++) {
            if (self::$cache[$z] > self::$cache[$z - 1]) {
                self::$cache[$z] = self::$cache[$z - 1];
            }
        }

        for ($z = self::$minZoom; $z <= self::$maxZoom; $z++) {
            $value = self::$cache[$z];

            if ($value < $minPrecision) {
                $value = $minPrecision;
            } elseif ($value > $maxPrecision) {
                $value = $maxPrecision;
            }

            self::$cache[$z] = $value;
        }

        self::$booted = true;
    }

    public static function observeMeters(?callable $observer): void
    {
        self::$meterObserver = $observer;
    }
}
