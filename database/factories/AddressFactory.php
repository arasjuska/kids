<?php

namespace Database\Factories;

use App\Enums\AccuracyLevelEnum;
use App\Enums\AddressTypeEnum;
use App\Models\Address;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Address>
 */
class AddressFactory extends Factory
{
    protected $model = Address::class;

    public function definition(): array
    {
        $lat = $this->faker->latitude(53.5, 56.5);
        $lon = $this->faker->longitude(20.0, 26.0);

        return [
            'formatted_address' => $this->faker->streetAddress.', '.$this->faker->city,
            'short_address_line' => $this->faker->streetName,
            'street_name' => $this->faker->streetName,
            'street_number' => (string) $this->faker->numberBetween(1, 200),
            'city' => $this->faker->city,
            'state' => $this->faker->state,
            'postal_code' => $this->faker->postcode,
            'country' => 'Lithuania',
            'country_code' => 'lt',
            'latitude' => $lat,
            'longitude' => $lon,
            'address_type' => AddressTypeEnum::UNVERIFIED->value,
            'confidence_score' => 0.90,
            'geocoding_provider' => 'nominatim',
            'accuracy_level' => AccuracyLevelEnum::UNKNOWN->value,
            'quality_tier' => 'good',
            'manually_overridden' => false,
            'source_locked' => false,
            'requires_verification' => false,
            'raw_api_response' => [],
            'is_virtual' => false,
        ];
    }
}
