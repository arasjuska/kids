<?php

namespace App\Observers;

use App\Enums\AddressTypeEnum;
use App\Models\Address;
use App\Models\AddressAudit;
use App\Support\AddressNormalizer;
use App\Support\QualityCalculator;
use App\Support\SourceLock;
use BackedEnum;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

final class AddressObserver
{
    public function __construct(
        private readonly AddressNormalizer $normalizer,
        private readonly QualityCalculator $qualityCalculator,
        private readonly SourceLock $sourceLock,
    ) {}

    public function creating(Address $address): void
    {
        $this->applySignature($address);
        $this->recalculateQuality($address, true, []);
        $address->source_locked = $this->sourceLock->shouldLock($address);
    }

    public function updating(Address $address): void
    {
        $dirty = $address->getDirty();
        $hierarchyDirtyFields = $this->changedHierarchyFields($address);

        if ($this->sourceLock->needsOverride($address, $dirty)) {
            $reason = (string) ($dirty['override_reason'] ?? $address->override_reason ?? '');
            $this->sourceLock->beginOverride($address, $dirty, $reason, Auth::id());
        } elseif ($this->sourceLock->shouldLock($address) && $hierarchyDirtyFields !== []) {
            throw ValidationException::withMessages([
                'override_reason' => 'Provide an override_reason to modify locked address fields.',
            ]);
        } elseif ($hierarchyDirtyFields === [] && array_key_exists('override_reason', $dirty) && trim((string) $address->override_reason) === '') {
            $address->override_reason = $address->getOriginal('override_reason');
        }

        if ($address->isDirty(['street_name', 'street_number', 'city', 'country_code', 'postal_code'])) {
            $this->applySignature($address);
        }

        if ($address->isDirty('verified_at') && $address->verified_at) {
            $address->requires_verification = false;

            AddressAudit::create([
                'address_id' => $address->id,
                'user_id' => Auth::id(),
                'action' => 'confirm',
                'changed_fields' => [],
                'override_reason' => null,
                'created_at' => now(),
            ]);
        }

        if ($this->shouldRecalculateQuality($address)) {
            $this->recalculateQuality($address, false, $hierarchyDirtyFields);
        }

        $address->source_locked = $this->sourceLock->shouldLock($address);
    }

    private function shouldRecalculateQuality(Address $address): bool
    {
        return $address->isDirty([
            'accuracy_level',
            'geocoding_provider',
            'confidence_score',
            'manually_overridden',
            'city',
            'postal_code',
            'country_code',
        ]);
    }

    private function recalculateQuality(Address $address, bool $force = false, ?array $overriddenFields = null): void
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

        $overriddenFields ??= $this->changedHierarchyFields($address);

        $result = $this->qualityCalculator->compute(
            (string) $accuracy,
            $providerConfidence,
            (bool) $address->manually_overridden,
            $overriddenFields
        );

        $address->confidence_score = round($result['confidence'], 2);
        $address->quality_tier = $result['tier'];

        $requires = ($address->manually_overridden && $overriddenFields !== [])
            ? true
            : $result['requires_verification'];

        if ($address->verified_at !== null) {
            $requires = false;
        } else {
            $type = $address->address_type;
            if ($type instanceof AddressTypeEnum) {
                $type = $type->value;
            }

            if (strcasecmp((string) $type, AddressTypeEnum::VERIFIED->value) === 0) {
                $requires = false;
            }
        }

        $address->requires_verification = $requires;
        $address->fields_refreshed_at = now();
    }

    private function changedHierarchyFields(Address $address): array
    {
        $changed = [];

        foreach (SourceLock::HIERARCHICAL_FIELDS as $field) {
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
