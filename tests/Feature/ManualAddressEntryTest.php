<?php

declare(strict_types=1);

use App\Contracts\GeocodingServiceInterface;
use App\Data\GeocodeResult;
use App\Enums\AddressTypeEnum;
use App\Filament\Resources\Places\Pages\CreatePlace;
use App\Models\Address;
use App\Models\Place;
use App\Models\User;
use App\Services\AddressFormStateManager;
use Filament\Facades\Filament;
use Illuminate\Support\Collection;
use Livewire\Livewire;

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    app()->instance(GeocodingServiceInterface::class, new class implements GeocodingServiceInterface {
        public function forward(string $query, ?string $countryCode = null): ?GeocodeResult
        {
            return null;
        }

        public function reverse(float $lat, float $lon): ?GeocodeResult
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

it('persists manual address entry without geocoding interference', function (): void {
    $state = manualAddressState([
        'city' => 'Vilnius',
        'street_name' => 'Sodų g.',
        'street_number' => '12A',
        'postal_code' => '01133',
    ], function (AddressFormStateManager $manager): void {
        $manager->updateCoordinates(54.678, 25.281, false);
    });

    Livewire::test(CreatePlace::class)
        ->fillForm([
            'name' => 'Manual įrašas',
            'address_state' => $state,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $place = Place::query()->with('address')->firstOrFail();
    $address = $place->address;

    expect($address)->not->toBeNull()
        ->and($address->city)->toBe('Vilnius')
        ->and($address->street_name)->toBe('Sodų g.')
        ->and($address->street_number)->toBe('12A')
        ->and($address->postal_code)->toBe('01133')
        ->and($address->address_type)->toBe(AddressTypeEnum::UNVERIFIED);
});

it('allows approximate saves when house number is missing', function (): void {
    $state = manualAddressState([
        'city' => 'Kaunas',
        'street_name' => 'Laisvės al.',
    ]);

    Livewire::test(CreatePlace::class)
        ->fillForm([
            'name' => 'Be numerio',
            'address_state' => $state,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $address = Address::query()->firstOrFail();

    expect($address->street_number)->toBeNull()
        ->and($address->city)->toBe('Kaunas')
        ->and($address->address_type)->toBe(AddressTypeEnum::LOW_CONFIDENCE)
        ->and($address->confidence_score)->toBeLessThan(0.7);
});

it('rejects edits to source locked fields and surfaces validation errors', function (): void {
    $lockedState = manualAddressState([
        'city' => 'Vilnius',
        'street_name' => 'Sodų g.',
    ], function (AddressFormStateManager $manager): void {
        $manager->setSourceFieldLocks(['city']);
    });

    /** @var AddressFormStateManager $manager */
    $manager = app(AddressFormStateManager::class);
    $manager->restoreState($lockedState);

    $attempt = fn () => $manager->updateManualField('city', 'Kaunas');

    expect($attempt)->toThrow(InvalidArgumentException::class);
});
