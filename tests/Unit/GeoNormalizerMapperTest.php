<?php

use App\Support\GeoNormalizer;

it('maps provider suggestion and produces canonical payload with signature', function () {
    $raw = [
        'lat' => '54.687157',
        'lon' => '25.279652',
        'address' => [
            'city' => 'Vilnius',
            'road' => 'Gedimino pr.',
            'house_number' => '1',
            'postcode' => '01103',
            'country' => 'Lithuania',
        ],
        'display_name' => 'Gedimino pr. 1, Vilnius, 01103, Lithuania',
        'osm_id' => 123456789,
        'osm_type' => 'way',
    ];

    $out = app(GeoNormalizer::class)->mapProviderSuggestion($raw);

    expect($out)->toBeArray()
        ->and($out)->toHaveKeys([
            'latitude',
            'longitude',
            'street',
            'house_number',
            'city',
            'region',
            'postcode',
            'country_code',
            'address_signature',
        ])
        ->and($out['street'])->toBe('Gedimino pr.')
        ->and($out['house_number'])->toBe('1')
        ->and($out)->toHaveKey('country_code')
        ->and($out['address_signature'])->toMatch('/^[0-9a-f]{64}$/');
});

it('handles incomplete payloads without crashing', function () {
    $raw = [
        'lat' => '55.00001',
        'lon' => '24.00001',
        'display_name' => 'Random location',
    ];

    $out = app(GeoNormalizer::class)->mapProviderSuggestion($raw);

    expect($out)->toBeArray()
        ->and($out)->toHaveKeys(['latitude', 'longitude', 'raw_payload'])
        ->and($out['latitude'])->toBeFloat()
        ->and($out['longitude'])->toBeFloat()
        ->and($out)->not->toHaveKey('address_signature');
});

it('keeps country_code lowercase even if provider returns uppercase', function () {
    $raw = [
        'lat' => '54.9001',
        'lon' => '23.7001',
        'address' => [
            'road' => 'Sodų g.',
            'house_number' => '7',
            'city' => 'Piliuona',
            'postcode' => '59001',
            'country' => 'Lithuania',
            'country_code' => 'LT',
        ],
        'display_name' => 'Sodų g. 7, Piliuona',
    ];

    $out = app(GeoNormalizer::class)->mapProviderSuggestion($raw);

    expect($out['country_code'])->toBe('lt')
        ->and($out['address_signature'])->toMatch('/^[0-9a-f]{64}$/');
});

it('handles missing region but emits the key', function () {
    $raw = [
        'lat' => '54.000001',
        'lon' => '24.000001',
        'address' => [
            'road' => 'Example st.',
            'city' => 'Kaunas',
            'country' => 'Lithuania',
            'country_code' => 'lt',
        ],
    ];

    $out = app(GeoNormalizer::class)->mapProviderSuggestion($raw);

    expect($out)->toHaveKey('region')
        ->and($out['region'])->toBeNull()
        ->and($out)->toHaveKey('country_code')
        ->and($out['country_code'])->toBe('lt');
});
