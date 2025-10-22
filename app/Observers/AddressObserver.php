<?php

namespace App\Observers;

use App\Models\Address;
use App\Support\AddressNormalizer;

final class AddressObserver
{
    public function creating(Address $address): void
    {
        $this->apply($address);
    }

    public function updating(Address $address): void
    {
        if ($address->isDirty(['street_name', 'street_number', 'city', 'country_code', 'postal_code'])) {
            $this->apply($address);
        }
    }

    private function apply(Address $address): void
    {
        $normalizer = app(AddressNormalizer::class);

        $signature = $normalizer->signature([
            'street_name' => $address->street_name,
            'street_number' => $address->street_number,
            'city' => $address->city,
            'country_code' => $address->country_code,
            'postal_code' => $address->postal_code,
        ]);

        $address->address_signature = $signature;
    }
}
