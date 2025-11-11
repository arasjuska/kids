<?php

namespace App\Console\Commands;

use App\Services\MapClusterService;
use App\Support\PrecisionFromZoom;
use Illuminate\Console\Command;

class MapWarmupCommand extends Command
{
    protected $signature = 'map:warmup
        {--zooms=3,6,9,12,15 : Comma separated zoom levels to preheat}
        {--bbox=25.20,54.60,25.40,54.80 : Bounding box (west,south,east,north) for cluster warm-up}
        {--force : Force cluster warm-up even outside production}';

    protected $description = 'Warm up map precision and cluster caches.';

    public function handle(MapClusterService $clusterService): int
    {
        $zooms = $this->parseZoomLevels((string) $this->option('zooms'));
        $bbox = $this->parseBoundingBox((string) $this->option('bbox'));

        $precisionMs = $this->benchmark(function () use ($zooms): void {
            foreach ($zooms as $zoom) {
                PrecisionFromZoom::meters($zoom);
            }
        });

        $this->info(sprintf(
            'Precision cache primed for zooms [%s] in %.1f ms.',
            implode(',', $zooms),
            $precisionMs,
        ));

        $shouldTouchClusters = $this->option('force')
            || app()->environment('production')
            || (bool) config('map.warmup');

        if (! $shouldTouchClusters) {
            $this->comment('Skipping cluster warm-up (use --force to override outside production).');

            return self::SUCCESS;
        }

        try {
            $clusterMs = $this->benchmark(function () use ($clusterService, $bbox, $zooms): void {
                foreach ($zooms as $zoom) {
                    $clusterService->query($bbox, $zoom);
                }
            });

            $this->info(sprintf(
                'Cluster cache warmed for bbox [%s] in %.1f ms.',
                implode(',', [$bbox['west'], $bbox['south'], $bbox['east'], $bbox['north']]),
                $clusterMs,
            ));
        } catch (\Throwable $exception) {
            $this->error('Cluster warm-up failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @return array<int, int>
     */
    private function parseZoomLevels(string $value): array
    {
        $parts = array_filter(array_map('trim', explode(',', $value)));
        $zooms = [];

        foreach ($parts as $part) {
            if (! is_numeric($part)) {
                continue;
            }

            $zooms[] = max(0, (int) $part);
        }

        if ($zooms === []) {
            $zooms = [3, 6, 9, 12, 15];
        }

        return array_values(array_unique($zooms));
    }

    /**
     * @return array{north: float, south: float, east: float, west: float}
     */
    private function parseBoundingBox(string $value): array
    {
        $parts = array_map('trim', explode(',', $value));

        if (count($parts) !== 4) {
            $parts = ['25.20', '54.60', '25.40', '54.80'];
        }

        return [
            'west' => (float) $parts[0],
            'south' => (float) $parts[1],
            'east' => (float) $parts[2],
            'north' => (float) $parts[3],
        ];
    }

    private function benchmark(callable $callback): float
    {
        $start = microtime(true);
        $callback();

        return (microtime(true) - $start) * 1000;
    }
}
