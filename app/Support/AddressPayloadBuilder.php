<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Carbon;
use InvalidArgumentException;

final class AddressPayloadBuilder
{
    /**
     * Build a persistence-ready payload for the Address model using the canonical DTO.
     *
     * @param  array<string, mixed>  $normalized
     * @return array<string, mixed>
     */
    public static function fromNormalized(array $normalized): array
    {
        $dto = AddressData::fromArray($normalized);

        if (! $dto->hasCoordinates()) {
            throw new InvalidArgumentException('Cannot save an address without coordinates.');
        }

        $dto->ensureSnapshot(Carbon::now());

        if (! $dto->isConfirmed()) {
            throw new InvalidArgumentException('Cannot save an address without a confirmation snapshot.');
        }

        $payload = $dto->toPersistenceAttributes();

        if (! isset($payload['latitude'], $payload['longitude'])) {
            throw new InvalidArgumentException('Normalized coordinates missing from address payload.');
        }

        $payload['address_type'] = $normalized['address_type'] ?? 'unverified';
        $payload['confidence_score'] = $normalized['confidence_score'] ?? null;
        $payload['description'] = $normalized['description'] ?? null;
        $payload['raw_api_response'] = $normalized['raw_api_response'] ?? ($payload['raw_api_response'] ?? null);
        $payload['is_virtual'] = ($normalized['address_type'] ?? null) === 'virtual';
        $payload['provider'] = $normalized['provider'] ?? ($payload['provider'] ?? 'nominatim');
        $payload['provider_place_id'] = $normalized['provider_place_id'] ?? null;
        $payload['osm_type'] = $normalized['osm_type'] ?? null;
        $payload['osm_id'] = isset($normalized['osm_id']) ? (int) $normalized['osm_id'] : null;

        if (array_key_exists('address_signature', $normalized)) {
            $payload['address_signature'] = $normalized['address_signature'];
        }

        $payload['source_locked'] = (bool) ($normalized['source_locked'] ?? false);
        $payload['override_reason'] = $normalized['override_reason'] ?? null;

        return $payload;
    }
}
