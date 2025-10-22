<?php

use App\Enums\AddressTypeEnum;
use App\Enums\AccuracyLevelEnum;
use App\Models\Address;
use App\Support\AddressNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\QueryException;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('normalizes and signs consistently', function () {
    $normalizer = app(AddressNormalizer::class);

    $signatureA = $normalizer->signature([
        'street_name' => 'A. Juozapavičiaus g.',
        'street_number' => '4-2',
        'city' => 'Kaunas',
        'country_code' => 'lt',
        'postal_code' => 'LT-45261',
    ]);

    $signatureB = $normalizer->signature([
        'street_name' => 'a juozapaviciaus gatve',
        'street_number' => '4/2',
        'city' => ' kaunas ',
        'country_code' => 'LT',
        'postal_code' => 'lt45261',
    ]);

    expect($signatureA)->not->toBeNull();
    expect(strlen($signatureA))->toBe(32);
    expect($signatureA)->toBe($signatureB);
});

it('prevents duplicate canonical signatures', function () {
    $data = [
        'formatted_address' => 'A. Juozapavičiaus g. 4-2, Kaunas',
        'short_address_line' => 'Juozapavičiaus g. 4-2',
        'street_name' => 'A. Juozapavičiaus g.',
        'street_number' => '4-2',
        'city' => 'Kaunas',
        'state' => null,
        'postal_code' => 'LT-45261',
        'country' => 'Lietuva',
        'country_code' => 'LT',
        'latitude' => 54.88800000,
        'longitude' => 23.94200000,
        'address_type' => AddressTypeEnum::UNVERIFIED,
        'confidence_score' => 0.95,
        'description' => null,
        'raw_api_response' => null,
        'is_virtual' => false,
        'geocoding_provider' => 'maps',
        'accuracy_level' => AccuracyLevelEnum::ROOFTOP->value,
        'quality_tier' => 1,
        'verified_at' => now(),
        'fields_refreshed_at' => now(),
        'manually_overridden' => false,
        'source_locked' => false,
        'provider' => 'maps',
        'provider_place_id' => 'place-123',
        'osm_type' => 'way',
        'osm_id' => 123456,
    ];

    $first = Address::create($data);
    expect($first->address_signature)->not->toBeNull();

    expect(fn () => Address::create(array_merge($data, [
        'street_number' => '4/2',
        'provider_place_id' => 'place-456',
        'osm_id' => 654321,
    ])))->toThrow(QueryException::class);
});
