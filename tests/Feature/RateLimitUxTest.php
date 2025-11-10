<?php

use App\Contracts\GeocodingServiceInterface;
use App\Services\AddressFormStateManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function (): void {
    Cache::flush();
    RateLimiter::clear('geo:throttle:nominatim');
    Http::preventStrayRequests();
});

it('shows warning when rate limited', function (): void {
    Http::fake([
        '*' => Http::response([], 429),
    ]);

    $service = app(GeocodingServiceInterface::class);
    $manager = new AddressFormStateManager($service);

    $results = $service->search('Kaunas', ['country_codes' => 'lt', 'limit' => 1]);
    $manager->handleSearchResults($results);

    $warnings = data_get($manager->getStateSnapshot(), 'messages.warnings', []);

    expect($warnings)->toContain(__('address.rate_limited'));
});

it('shows warning when breaker opens', function (): void {
    Http::fake([
        '*' => Http::response([], 502),
    ]);

    $service = app(GeocodingServiceInterface::class);
    $manager = new AddressFormStateManager($service);

    $threshold = config('geocoding.breaker.failure_threshold');

    for ($i = 0; $i < $threshold; $i++) {
        $service->search('Vilnius '.$i, ['country_codes' => 'lt', 'limit' => 1]);
    }

    $results = $service->search('Vilnius final', ['country_codes' => 'lt', 'limit' => 1]);
    $manager->handleSearchResults($results);

    $warnings = data_get($manager->getStateSnapshot(), 'messages.warnings', []);

    expect($warnings)->toContain(__('address.provider_offline'));
});
