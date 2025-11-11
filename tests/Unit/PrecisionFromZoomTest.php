<?php

use App\Services\MapClusterService;
use App\Support\PrecisionFromZoom;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    Config::set('map_clusters.precision_by_zoom', [
        1 => 1.00,
        2 => 1.00,
        3 => 0.75,
        4 => 0.50,
        5 => 0.25,
        6 => 0.20,
        7 => 0.10,
        8 => 0.08,
        9 => 0.05,
        10 => 0.025,
        11 => 0.015,
        12 => 0.010,
    ]);

    Config::set('map_clusters.precision_min', 0.005);
    Config::set('map_clusters.precision_max', 2.0);

    PrecisionFromZoom::refresh();
});

function precisionService(): MapClusterService
{
    return app(MapClusterService::class);
}

it('uses exact table values when present', function (): void {
    $service = precisionService();

    expect($service->precisionFromZoom(5))->toBe(0.25)
        ->and($service->precisionFromZoom(7))->toBe(0.10)
        ->and($service->precisionFromZoom(10))->toBe(0.025)
        ->and($service->precisionFromZoom(12))->toBe(0.010);
});

it('falls back to nearest lower defined zoom when missing', function (): void {
    $map = Config::get('map_clusters.precision_by_zoom');
    unset($map[9]);
    Config::set('map_clusters.precision_by_zoom', $map);

    PrecisionFromZoom::refresh();

    expect(precisionService()->precisionFromZoom(9))->toBe(0.08);
});

it('is non-increasing as zoom grows', function (): void {
    $service = precisionService();
    $last = null;

    foreach ([3, 4, 5, 6, 7, 8, 9, 10, 11, 12] as $zoom) {
        $value = $service->precisionFromZoom($zoom);

        if ($last !== null) {
            expect($value)->toBeLessThanOrEqual($last);
        }

        $last = $value;
    }
});

it('clamps to configured bounds', function (): void {
    Config::set('map_clusters.precision_min', 0.01);
    Config::set('map_clusters.precision_max', 0.5);

    PrecisionFromZoom::refresh();

    $service = precisionService();

    expect($service->precisionFromZoom(1))->toBeLessThanOrEqual(0.5)
        ->and($service->precisionFromZoom(999))->toBeGreaterThanOrEqual(0.01);
});

it('handles invalid zoom inputs gracefully', function (): void {
    $service = precisionService();

    expect($service->precisionFromZoom(0))->toBeFloat()
        ->and($service->precisionFromZoom(-10))->toBeFloat()
        ->and($service->precisionFromZoom(999))->toBeFloat();
});

it('is fast for repeated calls', function (): void {
    $service = precisionService();
    $start = microtime(true);

    for ($i = 0; $i < 10000; $i++) {
        $service->precisionFromZoom(($i % 20) - 1);
    }

    $elapsedMs = (microtime(true) - $start) * 1000;

    expect($elapsedMs)->toBeLessThan(250);
});
