<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use App\Services\MapClusterService;
use App\Support\PrecisionFromZoom;
use Illuminate\Support\Facades\Config;
use function Pest\Laravel\artisan;

afterEach(function (): void {
    PrecisionFromZoom::observeMeters(null);
    Config::set('map.warmup', false);
    \Mockery::close();
});

it('skips automatic warm-up during tests', function (): void {
    $calls = [];
    PrecisionFromZoom::observeMeters(function (int $zoom) use (&$calls): void {
        $calls[] = $zoom;
    });

    Config::set('map.warmup', true);

    $provider = new AppServiceProvider(app());
    $provider->boot();

    expect($calls)->toBeEmpty();
});

it('warms precision cache via artisan command', function (): void {
    $calls = [];
    PrecisionFromZoom::observeMeters(function (int $zoom) use (&$calls): void {
        $calls[] = $zoom;
    });

    $clusterMock = \Mockery::mock(MapClusterService::class);
    $clusterMock->shouldReceive('query')->never();
    app()->instance(MapClusterService::class, $clusterMock);

    artisan('map:warmup', ['--zooms' => '4,8'])
        ->expectsOutputToContain('Precision cache primed')
        ->expectsOutputToContain('Skipping cluster warm-up')
        ->assertExitCode(0);

    expect($calls)->toBe([4, 8]);
});
