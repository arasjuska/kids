<?php

declare(strict_types=1);

use App\Support\NominatimResultMapper;

it('uses city when provided', function (): void {
    $mapped = NominatimResultMapper::toAddressArray([
        'address' => [
            'city' => 'Kaunas',
            'road' => 'Petrausko g.',
            'house_number' => '13',
            'postcode' => '01133',
            'state' => 'Kauno apskritis',
            'country' => 'Lithuania',
        ],
    ]);

    expect($mapped['city'])->toBe('Kaunas');
});

it('falls back to town/village/hamlet/suburb', function (): void {
    $town = NominatimResultMapper::toAddressArray(['address' => ['town' => 'Piliuona']]);
    $village = NominatimResultMapper::toAddressArray(['address' => ['village' => 'Piliuona']]);
    $hamlet = NominatimResultMapper::toAddressArray(['address' => ['hamlet' => 'Piliuona']]);
    $suburb = NominatimResultMapper::toAddressArray(['address' => ['suburb' => 'Aleksotas']]);

    expect($town['city'])->toBe('Piliuona')
        ->and($village['city'])->toBe('Piliuona')
        ->and($hamlet['city'])->toBe('Piliuona')
        ->and($suburb['city'])->toBe('Aleksotas');
});

it('maps other address attributes when present', function (): void {
    $mapped = NominatimResultMapper::toAddressArray([
        'address' => [
            'road' => 'Sodų g.',
            'pedestrian' => 'Alternatyvi',
            'house_number' => '7',
            'postcode' => '59001',
            'municipality' => 'Kauno r. sav.',
            'state' => 'Kauno apskritis',
            'country' => 'Lietuva',
        ],
    ]);

    expect($mapped['street'])->toBe('Sodų g.')
        ->and($mapped['house_number'])->toBe('7')
        ->and($mapped['postcode'])->toBe('59001')
        ->and($mapped['municipality'])->toBe('Kauno r. sav.')
        ->and($mapped['region'])->toBe('Kauno apskritis')
        ->and($mapped['country'])->toBe('Lietuva');
});
