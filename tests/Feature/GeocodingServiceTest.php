<?php

use App\Contracts\GeocodingServiceInterface;
use App\Support\GeoNormalizer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

beforeEach(function () {
    Cache::flush();
    RateLimiter::clear('geo:throttle:nominatim');
    Http::preventStrayRequests();
});

it('caches forward results', function () {
    Http::fake([
        '*' => Http::response([[
            'place_id' => 'abc',
            'display_name' => 'Example Address',
            'lat' => 54.0,
            'lon' => 24.0,
            'address' => ['city' => 'Vilnius', 'country_code' => 'lt'],
            'accuracy' => 'rooftop',
            'confidence' => 0.9,
        ]]),
    ]);

    $service = app(GeocodingServiceInterface::class);
    $first = $service->forward('   Example Address  ', 'lt');

    expect($first)->not()->toBeNull();
    Http::assertSentCount(1);

    $second = $service->forward('example address', 'LT');

    expect($second)->not()->toBeNull()
        ->and($second->placeId)->toBe('abc');
    Http::assertSentCount(1);
});

it('opens breaker after repeated failures', function () {
    Http::fake([
        '*' => Http::response([], 502),
    ]);

    $service = app(GeocodingServiceInterface::class);

    for ($i = 0; $i < config('geocoding.breaker.failure_threshold'); $i++) {
        $service->forward('fail-' . $i);
    }

    Http::fake([
        '*' => Http::response([['place_id' => 'late', 'display_name' => 'Any', 'lat' => 1, 'lon' => 1]], 200),
    ]);

    $blocked = $service->forward('blocked');
    expect($blocked)->toBeNull();
});

it('respects throttle limit', function () {
    Http::fake([
        '*' => Http::response([[
            'place_id' => Str::uuid()->toString(),
            'display_name' => 'Any',
            'lat' => 1,
            'lon' => 1,
        ]]),
    ]);

    $service = app(GeocodingServiceInterface::class);
    $limit = config('geocoding.throttle.rps');

    for ($i = 0; $i < $limit; $i++) {
        $service->forward('throttle-' . $i);
    }

    $blocked = $service->forward('throttle-block');
    expect($blocked)->toBeNull();
});

it('normalizes forward queries', function () {
    $normalizer = app(GeoNormalizer::class);
    $normalized = $normalizer->normalizeForwardQuery(" Kauno   g. 7 \n", 'lt');

    expect($normalized)->toBe([
        'q' => 'kauno g. 7',
        'cc' => 'LT',
    ]);
});

it('rounds reverse coordinates by accuracy', function () {
    $normalizer = app(GeoNormalizer::class);

    $default = $normalizer->roundForReverse(54.123456, 24.654321, 'UNKNOWN');
    expect($default)->toBe([
        'lat' => round(54.123456, config('geocoding.rounding.reverse.default')),
        'lon' => round(24.654321, config('geocoding.rounding.reverse.default')),
    ]);

    $rooftop = $normalizer->roundForReverse(54.123456, 24.654321, 'ROOFTOP');
    expect($rooftop)->toBe([
        'lat' => round(54.123456, config('geocoding.rounding.reverse.ROOFTOP')),
        'lon' => round(24.654321, config('geocoding.rounding.reverse.ROOFTOP')),
    ]);
});
