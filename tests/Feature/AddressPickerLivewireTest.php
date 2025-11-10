<?php

declare(strict_types=1);

use App\Filament\Resources\Places\Pages\CreatePlace;
use App\Models\Place;
use App\Models\User;
use App\Services\AddressFormStateManager;
use App\Support\GeoNormalizer;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $this->actingAs(User::factory()->create());
});

it('persists Kaunas selection with full details', function (): void {
    $address = selectAndSaveAddress(rawKaunasPayload(), 'Kaunas spot');

    expect($address->city)->toBe('Kaunas')
        ->and($address->street_name)->toBe('Petrausko g.')
        ->and($address->street_number)->toBe('13')
        ->and($address->postal_code)->toBe('01133');
});

it('falls back to Piliuona when city is missing', function (): void {
    $address = selectAndSaveAddress(rawPiliuonaPayload(), 'Piliuona sodai');

    expect($address->city)->toBe('Piliuona')
        ->and($address->street_name)->toBe('Sodų g.')
        ->and($address->street_number)->toBe('7');
});

it('keeps manual city edits without clearing other fields', function (): void {
    $manager = prepareManagerWithSuggestion(rawPiliuonaPayload());
    $manager->updateManualField('city', 'Piliuona (mano)');

    $address = saveAddressFromManager($manager, 'Manual Piliuona');

    expect($address->city)->toBe('Piliuona (mano)')
        ->and($address->street_name)->toBe('Sodų g.')
        ->and($address->street_number)->toBe('7');
});

function rawKaunasPayload(): array
{
    return [
        'place_id' => 501,
        'display_name' => 'Petrausko g. 13, Kaunas',
        'lat' => 54.896123,
        'lon' => 23.918765,
        'address' => [
            'road' => 'Petrausko g.',
            'house_number' => '13',
            'city' => 'Kaunas',
            'postcode' => '01133',
            'state' => 'Kauno apskritis',
            'country' => 'Lietuva',
            'country_code' => 'lt',
        ],
    ];
}

function rawPiliuonaPayload(): array
{
    return [
        'place_id' => 777,
        'display_name' => 'Sodų g. 7, Piliuona',
        'lat' => 54.900100,
        'lon' => 23.700100,
        'address' => [
            'road' => 'Sodų g.',
            'house_number' => '7',
            'village' => 'Piliuona',
            'municipality' => 'Kauno r. sav.',
            'state' => 'Kauno apskritis',
            'country' => 'Lietuva',
            'country_code' => 'lt',
        ],
    ];
}

function selectAndSaveAddress(array $raw, string $name): \App\Models\Address
{
    $manager = prepareManagerWithSuggestion($raw);

    return saveAddressFromManager($manager, $name);
}

function prepareManagerWithSuggestion(array $raw): AddressFormStateManager
{
    /** @var GeoNormalizer $normalizer */
    $normalizer = app(GeoNormalizer::class);
    $suggestion = $normalizer->mapProviderSuggestion($raw);

    /** @var AddressFormStateManager $manager */
    $manager = app(AddressFormStateManager::class);
    $manager->handleSearchResults(collect([$suggestion]));
    $manager->selectSuggestion($suggestion['place_id']);

    return $manager;
}

function saveAddressFromManager(AddressFormStateManager $manager, string $name): \App\Models\Address
{
    $state = $manager->getStateSnapshot();

    Livewire::test(CreatePlace::class)
        ->fillForm([
            'name' => $name,
            'address_state' => $state,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    return Place::with('address')->latest('id')->firstOrFail()->address;
}
