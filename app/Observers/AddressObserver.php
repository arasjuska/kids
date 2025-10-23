<?php

namespace App\Observers;

use App\Models\Address;
use App\Support\AddressNormalizer;
use App\Support\QualityCalculator;
use BackedEnum;

final class AddressObserver
{
    public function __construct(
        private readonly AddressNormalizer $normalizer,
        private readonly QualityCalculator $qualityCalculator,
    ) {
    }

    public function creating(Address $address): void
    {
        $this->applySignature($address);
        $this->recalculateQuality($address, true);
    }

    public function updating(Address $address): void
    {
        if ($address->isDirty(['street_name', 'street_number', 'city', 'country_code', 'postal_code'])) {
            $this->applySignature($address);
        }

        if ($this->shouldRecalculateQuality($address)) {
            $this->recalculateQuality($address);
        }
    }

    private function shouldRecalculateQuality(Address $address): bool
    {
        return $address->isDirty([
            'accuracy_level',
            'geocoding_provider',
            'confidence_score',
            'city',
            'postal_code',
            'country_code',
            'manually_overridden',
        ]);
    }

    private function recalculateQuality(Address $address, bool $force = false): void
    {
        if (! $force && ! $this->shouldRecalculateQuality($address)) {
            return;
        }

        $accuracy = $address->accuracy_level;
        if ($accuracy instanceof BackedEnum) {
            $accuracy = $accuracy->value;
        }
        $accuracy = $accuracy ?: 'UNKNOWN';

        $providerConfidence = $address->getAttribute('confidence_score');
        $providerConfidence = is_numeric($providerConfidence) ? (float) $providerConfidence : 0.0;

        $overriddenFields = $this->changedHierarchyFields($address);

        $result = $this->qualityCalculator->compute(
            (string) $accuracy,
            $providerConfidence,
            (bool) $address->manually_overridden,
            $overriddenFields
        );

        $address->confidence_score = round($result['confidence'], 2);
        $address->quality_tier = $result['tier'];
        $address->requires_verification = $result['requires_verification'];
        $address->fields_refreshed_at = now();
    }

    private function changedHierarchyFields(Address $address): array
    {
        $changed = [];

        foreach (['city', 'postal_code', 'country_code'] as $field) {
            if ($address->isDirty($field) && $address->getOriginal($field) !== $address->{$field}) {
                $changed[] = $field;
            }
        }

        return $changed;
    }

    private function applySignature(Address $address): void
    {
        $signature = $this->normalizer->signature([
            'street_name' => $address->street_name,
            'street_number' => $address->street_number,
            'city' => $address->city,
            'country_code' => $address->country_code,
            'postal_code' => $address->postal_code,
        ]);

        $address->address_signature = $signature;
    }
}
