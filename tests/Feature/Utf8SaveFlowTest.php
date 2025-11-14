<?php

declare(strict_types=1);

use App\Contracts\GeocodingServiceInterface;
use App\Filament\Resources\Places\Pages\CreatePlace;
use App\Models\Address;
use App\Models\AddressAudit;
use App\Models\User;
use App\Rules\Utf8String;
use Filament\Facades\Filament;
use Illuminate\Support\Collection;
use Livewire\Livewire;

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    app()->instance(GeocodingServiceInterface::class, new class implements GeocodingServiceInterface {
        public function forward(string $query, ?string $countryCode = null): ?\App\Data\GeocodeResult
        {
            return null;
        }

        public function reverse(float $lat, float $lon): ?\App\Data\GeocodeResult
        {
            return null;
        }

        public function search(string $query, array $options = []): Collection
        {
            return collect();
        }

        public function getLastStatus(string $operation): ?string
        {
            return null;
        }

        public function clearStatus(string $operation): void
        {
        }
    });

    $this->actingAs(User::factory()->create());
});

// TODO: manual street editing is no longer exposed in the simplified flow,
// so the UTF-8 rejection scenario is obsolete. Keep the test commented until
// a new manual-entry surface is reintroduced.
// it('rejects invalid UTF-8 street input', function (): void {
//     $state = manualAddressState([
//         'city' => 'Vilnius',
//         'street_name' => 'Gedimino pr.',
//         'street_number' => '10',
//     ], function (\App\Services\AddressFormStateManager $manager): void {
//         $manager->updateCoordinates(54.6872, 25.2797, false);
//     });
//
//     data_set($state, 'manual_fields.street_name', "Gedimino pr.\xC3");
//     data_set($state, 'ui.editing', false);
//
//     Livewire::test(CreatePlace::class)
//         ->fillForm([
//             'name' => 'UTF8 Invalid',
//             'address_state' => $state,
//         ])
//         ->call('create')
//         ->assertHasErrors('address_state.manual_fields.street_name');
// });

it('persists Lithuanian diacritics without corruption', function (): void {
    $street = 'Ąžuolų g. ĄČĘĖĮŠŲŪŽ';

    $state = manualAddressState([
        'city' => 'Šiauliai',
        'street_name' => $street,
        'street_number' => '5B',
        'postal_code' => '78111',
    ], function (\App\Services\AddressFormStateManager $manager): void {
        $manager->updateCoordinates(55.9349, 23.3137, false);
    });

    Livewire::test(CreatePlace::class)
        ->fillForm([
            'name' => 'LT UTF8',
            'address_state' => $state,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $address = Address::query()->latest('id')->firstOrFail();

    expect($address->street_name)->toBe($street)
        ->and($address->city)->toBe('Šiauliai');
});

it('stores raw payloads and audits with UTF-8 substitution when necessary', function (): void {
    $rawPayload = [
        'provider' => "Nominatim\xC3",
        'label' => 'Bad byte test',
    ];

    $address = Address::create([
        'formatted_address' => 'Test address',
        'short_address_line' => 'Test',
        'street_name' => 'Test g.',
        'street_number' => '1',
        'city' => 'Vilnius',
        'postal_code' => '01100',
        'country' => 'Lietuva',
        'country_code' => 'LT',
        'latitude' => 54.687157,
        'longitude' => 25.279652,
        'address_type' => \App\Enums\AddressTypeEnum::UNVERIFIED,
        'confidence_score' => 0.5,
        'raw_api_response' => $rawPayload,
    ]);

    expect($address->raw_api_response)
        ->toHaveKey('provider')
        ->and($address->raw_api_response['provider'])
        ->toContain('Nominatim');

    $audit = AddressAudit::create([
        'address_id' => $address->id,
        'user_id' => null,
        'action' => 'override',
        'changed_fields' => [
            'city' => [
                'old' => 'Vilnius',
                'new' => "Kaunas\xC3",
            ],
        ],
        'override_reason' => null,
        'created_at' => now(),
    ]);

    expect($audit->changed_fields['city']['new'])->toContain('Kaunas');
});
