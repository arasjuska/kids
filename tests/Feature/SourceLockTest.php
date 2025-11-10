<?php

use App\Enums\AccuracyLevelEnum;
use App\Enums\AddressTypeEnum;
use App\Models\Address;
use App\Models\AddressAudit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function makeAddress(array $overrides = []): Address
{
    return Address::create(array_merge([
        'formatted_address' => 'Sodų g. 1, Vilnius',
        'short_address_line' => 'Sodų g. 1',
        'street_name' => 'Sodų g.',
        'street_number' => '1',
        'city' => 'Vilnius',
        'state' => null,
        'postal_code' => '01313',
        'country' => 'Lietuva',
        'country_code' => 'LT',
        'latitude' => 54.000000,
        'longitude' => 25.000000,
        'address_type' => AddressTypeEnum::UNVERIFIED,
        'confidence_score' => 0.95,
        'raw_api_response' => [],
        'is_virtual' => false,
        'geocoding_provider' => 'nominatim',
        'accuracy_level' => AccuracyLevelEnum::ROOFTOP,
        'quality_tier' => 'EXCELLENT',
        'verified_at' => null,
        'fields_refreshed_at' => null,
        'manually_overridden' => false,
        'source_locked' => false,
        'requires_verification' => false,
    ], $overrides));
}

it('requires override reason when locked hierarchical fields change', function () {
    $user = User::factory()->create();
    $address = makeAddress();

    $this->actingAs($user);

    expect($address->fresh()->source_locked)->toBeTrue();

    expect(fn () => $address->update([
        'city' => 'Kaunas',
    ]))->toThrow(ValidationException::class);

    expect(AddressAudit::count())->toBe(0);
});

it('applies override penalty and audit trail for locked edits', function () {
    $user = User::factory()->create();
    $address = makeAddress();

    $this->actingAs($user);

    $address->update([
        'city' => 'Kaunas',
        'override_reason' => 'Manual correction after municipality merge.',
    ]);

    $updated = $address->fresh();

    expect($updated->manually_overridden)->toBeTrue()
        ->and($updated->requires_verification)->toBeTrue()
        ->and($updated->confidence_score)->toBe(0.81)
        ->and($updated->source_locked)->toBeTrue();

    $audit = AddressAudit::latest('id')->first();

    expect($audit)->not->toBeNull()
        ->and($audit->action)->toBe('override')
        ->and($audit->user_id)->toBe($user->id)
        ->and($audit->changed_fields)->toMatchArray([
            'city' => ['old' => 'Vilnius', 'new' => 'Kaunas'],
        ]);
});

it('allows micro coordinate adjustments without override penalty', function () {
    $user = User::factory()->create();
    $address = makeAddress();

    $this->actingAs($user);

    $address->update([
        'latitude' => 54.000010,
        'longitude' => 25.000010,
    ]);

    $updated = $address->fresh();

    expect($updated->manually_overridden)->toBeFalse()
        ->and($updated->requires_verification)->toBeFalse()
        ->and($updated->confidence_score)->toBe(0.95)
        ->and($updated->source_locked)->toBeTrue()
        ->and(AddressAudit::count())->toBe(0);
});

it('confirms address and clears verification requirement', function () {
    $user = User::factory()->create();
    $address = makeAddress();

    $this->actingAs($user);

    $address->update([
        'city' => 'Kaunas',
        'override_reason' => 'Manual correction for confirmation path.',
    ]);

    $address->refresh()->update([
        'verified_at' => now(),
    ]);

    $confirmed = $address->fresh();

    expect($confirmed->requires_verification)->toBeFalse()
        ->and($confirmed->verified_at)->not->toBeNull()
        ->and($confirmed->source_locked)->toBeTrue();

    $audit = AddressAudit::where('action', 'confirm')->latest('id')->first();

    expect($audit)->not->toBeNull()
        ->and($audit->address_id)->toBe($confirmed->id);

    expect($confirmed->override_reason)->not->toBeNull();
});

it('locks verified addresses even with low accuracy', function () {
    $address = makeAddress([
        'address_type' => AddressTypeEnum::VERIFIED,
        'accuracy_level' => AccuracyLevelEnum::UNKNOWN,
        'confidence_score' => 0.64,
    ]);

    $fresh = $address->fresh();

    expect($fresh->source_locked)->toBeTrue()
        ->and($fresh->requires_verification)->toBeFalse();
});
