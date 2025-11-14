<?php

namespace App\Filament\Resources\Places\Pages\Concerns;

use App\Models\Address;
use App\Support\AddressPayloadBuilder;
use Filament\Notifications\Notification;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

trait HandlesAddressSnapshots
{
    protected function resolveAddressPayloadFromSnapshot(?array $snapshot): array
    {
        $snapshot ??= [];

        $snapshot['latitude'] = $snapshot['latitude']
            ?? Arr::get($snapshot, 'coordinates.latitude');
        $snapshot['longitude'] = $snapshot['longitude']
            ?? Arr::get($snapshot, 'coordinates.longitude');

        foreach ((array) Arr::get($snapshot, 'manual_fields') as $key => $value) {
            $snapshot[$key] = $snapshot[$key] ?? $value;
        }

        if (isset($snapshot['raw_api_payload']) && ! isset($snapshot['raw_api_response'])) {
            $snapshot['raw_api_response'] = $snapshot['raw_api_payload'];
        }

        if (! is_numeric($snapshot['latitude'] ?? null) || ! is_numeric($snapshot['longitude'] ?? null)) {
            Notification::make()
                ->title('Nepavyko išsaugoti adreso')
                ->body('Patvirtinkite lokaciją žemėlapyje.')
                ->danger()
                ->seconds(10)
                ->send();

            throw ValidationException::withMessages([
                'form' => ['Patvirtinkite lokaciją žemėlapyje.'],
            ]);
        }

        return AddressPayloadBuilder::fromNormalized($snapshot);
    }

    protected function persistAddressPayload(array $payload): Address
    {
        $addressSignature = $payload['address_signature'] ?? null;

        if ($addressSignature !== null) {
            $existing = Address::query()
                ->where('address_signature', $addressSignature)
                ->first();

            if ($existing) {
                $existing->fill($payload);
                $existing->save();

                return $existing;
            }
        }

        return Address::create($payload);
    }
}
