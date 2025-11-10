<?php

namespace App\Services;

use App\Models\Address;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

class MapClusterService
{
    public function __construct(
        private readonly CacheRepository $cache
    ) {
    }

    /**
     * @param  array{north: float, south: float, east: float, west: float}  $bounds
     */
    public function query(array $bounds, int $zoom, int|string|null $page = 1): array
    {
        $threshold = (int) config('map_clusters.markers_zoom_threshold', 12);

        if ($zoom >= $threshold) {
            return $this->markers($bounds, $zoom, $page);
        }

        return $this->clusters($bounds, $zoom);
    }

    /**
     * @param  array{north: float, south: float, east: float, west: float}  $bounds
     */
    protected function clusters(array $bounds, int $zoom): array
    {
        $precision = $this->precisionFromZoom($zoom);
        $roundedBounds = $this->roundedBounds($bounds, $precision);
        $cacheKey = $this->cacheKey('cluster', $zoom, $roundedBounds, $precision);
        $ttl = $this->cacheTtl();

        return $this->cache->remember($cacheKey, $ttl, function () use ($bounds, $precision) {
            $columns = ['id', 'latitude', 'longitude'];
            $addresses = Address::query()
                ->withinBounds($bounds['south'], $bounds['west'], $bounds['north'], $bounds['east'], $columns)
                ->get();

            return $this->buildClusterPayload($addresses, $precision);
        });
    }

    /**
     * @param  array{north: float, south: float, east: float, west: float}  $bounds
     */
    protected function markers(array $bounds, int $zoom, int|string|null $page = 1): array
    {
        $pageNumber = $this->normalizePage($page);
        $roundedBounds = $this->roundedBounds($bounds, null);
        $cacheKey = $this->cacheKey('markers', $zoom, $roundedBounds, null, $pageNumber);
        $ttl = $this->cacheTtl();

        return $this->cache->remember($cacheKey, $ttl, function () use ($bounds, $pageNumber) {
            $perPage = (int) config('map_clusters.markers_per_page', 1000);
            $columns = ['id', 'latitude', 'longitude', 'short_address_line', 'formatted_address'];

            $query = Address::query()
                ->withinBounds($bounds['south'], $bounds['west'], $bounds['north'], $bounds['east'], $columns)
                ->orderBy('id');

            $offset = ($pageNumber - 1) * $perPage;

            $rows = $query->skip($offset)->take($perPage + 1)->get();

            $hasMore = $rows->count() > $perPage;
            $items = $rows->take($perPage)->map(function (Address $address) {
                return [
                    'id' => $address->id,
                    'lat' => (float) $address->latitude,
                    'lon' => (float) $address->longitude,
                    'title' => $address->short_address_line ?? $address->formatted_address,
                ];
            })->values()->all();

            return [
                'mode' => 'markers',
                'items' => $items,
                'meta' => [
                    'page' => $pageNumber,
                    'per_page' => $perPage,
                    'has_more' => $hasMore,
                ],
            ];
        });
    }

    protected function buildClusterPayload(Collection $addresses, float $precision): array
    {
        $maxItems = (int) config('map_clusters.max_cluster_items', 3000);
        $currentPrecision = $precision;

        [$clusters, $total] = $this->aggregateClusters($addresses, $currentPrecision);

        $coarsened = false;
        while ($maxItems > 0 && count($clusters) > $maxItems) {
            $nextPrecision = $this->coarsenPrecision($currentPrecision);

            if ($nextPrecision === $currentPrecision) {
                break;
            }

            $currentPrecision = $nextPrecision;
            [$clusters, $total] = $this->aggregateClusters($addresses, $currentPrecision);
            $coarsened = true;
        }

        if ($coarsened) {
            Log::warning('MapClusterService.precision_step_back', [
                'target_precision' => $precision,
                'applied_precision' => $currentPrecision,
                'clusters' => count($clusters),
            ]);
        }

        return [
            'mode' => 'cluster',
            'items' => array_values($clusters),
            'meta' => [
                'precision' => $currentPrecision,
                'count' => $total,
            ],
        ];
    }

    protected function aggregateClusters(Collection $addresses, float $precision): array
    {
        $clusters = [];
        $decimals = $this->snapDecimals($precision);
        $total = 0;

        foreach ($addresses as $address) {
            $latKey = $this->snapValue((float) $address->latitude, $precision, $decimals);
            $lonKey = $this->snapValue((float) $address->longitude, $precision, $decimals);
            $key = $latKey.':'.$lonKey;

            if (! isset($clusters[$key])) {
                $clusters[$key] = [
                    'lat' => $latKey,
                    'lon' => $lonKey,
                    'count' => 0,
                ];
            }

            $clusters[$key]['count']++;
            $total++;
        }

        usort($clusters, fn ($a, $b) => $b['count'] <=> $a['count']);

        return [$clusters, $total];
    }

    protected function snapValue(float $value, float $precision, int $decimals): float
    {
        if ($precision <= 0) {
            return $value;
        }

        return round(round($value / $precision) * $precision, $decimals);
    }

    protected function snapDecimals(float $precision): int
    {
        $decimals = $this->decimalPlaces($precision);

        return min(6, $decimals + 1);
    }

    protected function decimalPlaces(float $value): int
    {
        $formatted = rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.');

        if (str_contains($formatted, '.')) {
            return strlen(explode('.', $formatted)[1]);
        }

        return 0;
    }

    /**
     * Return grid precision for a zoom level.
     */
    public function precisionFromZoom(int $zoom): float
    {
        $table = $this->normalizedPrecisionTable();
        $min = (float) config('map_clusters.precision_min', 0.005);
        $max = (float) config('map_clusters.precision_max', 2.0);

        $clampedZoom = max(1, min(18, $zoom));

        if (array_key_exists($clampedZoom, $table)) {
            return $this->clampPrecision((float) $table[$clampedZoom], $min, $max);
        }

        for ($probe = $clampedZoom - 1; $probe >= 1; $probe--) {
            if (array_key_exists($probe, $table)) {
                return $this->clampPrecision((float) $table[$probe], $min, $max);
            }
        }

        return $this->clampPrecision(0.50, $min, $max);
    }

    public function invokePrecision(int $zoom): float
    {
        return $this->precisionFromZoom($zoom);
    }

    protected function clampPrecision(float $precision, float $min, float $max): float
    {
        return max($min, min($max, $precision));
    }

    protected function normalizedPrecisionTable(): array
    {
        $table = (array) config('map_clusters.precision_by_zoom', []);
        $normalized = [];

        foreach ($table as $zoom => $precision) {
            if (! is_numeric($zoom) || ! is_numeric($precision)) {
                continue;
            }

            $normalized[(int) $zoom] = (float) $precision;
        }

        ksort($normalized, SORT_NUMERIC);

        return $normalized;
    }

    protected function precisionLevels(): array
    {
        $levels = array_unique(array_values($this->normalizedPrecisionTable()));
        sort($levels);

        return array_values($levels);
    }

    protected function coarsenPrecision(float $precision): float
    {
        $levels = $this->precisionLevels();
        $index = array_search($precision, $levels, true);

        if ($index === false) {
            $levels[] = $precision;
            sort($levels);
            $index = array_search($precision, $levels, true);
        }

        $nextIndex = min($index + 1, count($levels) - 1);

        return $levels[$nextIndex] ?? $precision;
    }

    protected function roundedBounds(array $bounds, ?float $precision): array
    {
        $decimals = $precision !== null
            ? $this->snapDecimals($precision)
            : (int) config('map_clusters.marker_bounds_decimals', 4);

        return [
            'north' => round($bounds['north'], $decimals),
            'south' => round($bounds['south'], $decimals),
            'east' => round($bounds['east'], $decimals),
            'west' => round($bounds['west'], $decimals),
        ];
    }

    protected function cacheKey(string $mode, int $zoom, array $bounds, ?float $precision, int $page = 0): string
    {
        $parts = [
            'clusters',
            $mode,
            'z'.$zoom,
            'n'.$bounds['north'],
            's'.$bounds['south'],
            'e'.$bounds['east'],
            'w'.$bounds['west'],
        ];

        if ($precision !== null) {
            $parts[] = 'p'.number_format($precision, 3, '.', '');
        }

        if ($page > 0) {
            $parts[] = 'page'.$page;
        }

        return implode(':', $parts);
    }

    protected function cacheTtl(): int
    {
        return (int) config('map_clusters.cache_ttl_seconds', 60);
    }

    protected function normalizePage(int|string|null $page): int
    {
        if (is_numeric($page)) {
            return max(1, (int) $page);
        }

        return 1;
    }
}
