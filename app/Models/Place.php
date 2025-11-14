<?php

namespace App\Models;

use App\Http\Requests\AddressRequest;
use App\Support\AddressPayloadBuilder;
use App\Support\TextNormalizer;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

class Place extends Model
{
    protected $fillable = [
        'address_id',
        'name',
    ];

    protected function name(): Attribute
    {
        return Attribute::make(
            set: static fn ($value) => is_string($value) || $value === null
                ? TextNormalizer::toNfc($value)
                : TextNormalizer::toNfc((string) $value)
        );
    }

    public function address()
    {
        return $this->belongsTo(Address::class);
    }

    public function saveAddress(array $addressData): void
    {
        $payload = $this->prepareAddressPayload($addressData);

        if (config('app.debug')) {
            Log::debug('Place: saving address', [
                'place_id' => $this->getKey(),
                'address_id' => $this->address_id,
                'payload_preview' => Arr::only($payload, [
                    'formatted_address',
                    'latitude',
                    'longitude',
                    'address_type',
                ]),
            ]);
        }

        $address = $this->address ?? $this->address()->first();

        if ($address) {
            $address->fill($payload);
            $address->save();
        } else {
            $address = Address::create($payload);
            $this->address()->associate($address);
            $this->saveQuietly();
        }

        if (config('app.debug')) {
            Log::debug('Place: address saved', [
                'place_id' => $this->getKey(),
                'address_id' => $address->getKey(),
            ]);
        }
    }

    protected function prepareAddressPayload(array $addressData): array
    {
        $normalized = AddressRequest::normalizePayload($addressData);

        return AddressPayloadBuilder::fromNormalized($normalized);
    }
}
