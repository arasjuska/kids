<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use App\Services\MapClusterService;
use App\Support\PrecisionFromZoom;
use function Pest\Laravel\artisan;

afterEach(function (): void {
    PrecisionFromZoom::observeMeters(null);
    \Mockery::close();
});

it('skips automatic warm-up during tests', function (): void {
    $calls = [];
    PrecisionFromZoom::observeMeters(function (int $zoom) use (&$calls): void {
        $calls[] = $zoom;
    });

    putenv('MAP_WARMUP=true');
    $_ENV['MAP_WARMUP'] = 'true';
    $_SERVER['MAP_WARMUP'] = 'true';

    $provider = new AppServiceProvider(app());
    $provider->boot();

    putenv('MAP_WARMUP');
    unset($_ENV['MAP_WARMUP'], $_SERVER['MAP_WARMUP']);

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
