<?php

declare(strict_types=1);

use App\Models\Address;
use App\Models\Place;

it('normalizes mixed-form diacritics to NFC', function (): void {
    $rawStreet = "A\u{0308}žuol\u{0173} g.";
    $rawCity = "S\u{030C}iauliai";

    $address = Address::factory()->create([
        'street_name' => $rawStreet,
        'city' => $rawCity,
    ]);

    expect($address->street_name)->toBe(\Normalizer::normalize($rawStreet, \Normalizer::FORM_C))
        ->and($address->city)->toBe(\Normalizer::normalize($rawCity, \Normalizer::FORM_C));
});

it('leaves already-normalized text unchanged', function (): void {
    $address = Address::factory()->create(['city' => 'Šilainiai']);

    $place = Place::create([
        'address_id' => $address->id,
        'name' => 'Ąžuolas klubas',
    ]);

    expect($address->city)->toBe('Šilainiai')
        ->and($place->name)->toBe('Ąžuolas klubas');
});

it('filters invalid UTF-8 sequences gracefully', function (): void {
    $address = Address::factory()->create([
        'street_name' => "Gedimino pr.\xC3",
    ]);

    expect($address->street_name)->toBe('Gedimino pr.');
});
