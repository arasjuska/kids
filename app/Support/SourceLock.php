<?php

namespace App\Support;

use App\Enums\AddressTypeEnum;
use App\Models\Address;
use App\Models\AddressAudit;
use Illuminate\Validation\ValidationException;

final class SourceLock
{
    public const HIERARCHICAL_FIELDS = ['city', 'postal_code', 'country_code'];

    public function shouldLock(Address $address): bool
    {
        if ($address->verified_at !== null) {
            return true;
        }

        $type = $address->address_type;
        if ($type instanceof AddressTypeEnum) {
            $type = $type->value;
        }

        if (strcasecmp((string) $type, AddressTypeEnum::VERIFIED->value) === 0) {
            return true;
        }

        $accuracy = $address->accuracy_level;
        if ($accuracy instanceof \BackedEnum) {
            $accuracy = $accuracy->value;
        }

        $accuracy = strtoupper((string) $accuracy);

        if (in_array($accuracy, ['ROOFTOP', 'RANGE_INTERPOLATED'], true)) {
            return true;
        }

        $confidence = (float) $address->confidence_score;

        return $confidence >= 0.80;
    }

    public function needsOverride(Address $address, array $incoming): bool
    {
        if (! $this->shouldLock($address)) {
            return false;
        }

        foreach (self::HIERARCHICAL_FIELDS as $field) {
            if (! array_key_exists($field, $incoming)) {
                continue;
            }

            $original = $address->getOriginal($field);
            $current = $address->{$field};

            if (! $this->valuesMatch($original, $current)) {
                return true;
            }
        }

        return false;
    }

    public function beginOverride(Address $address, array $incoming, string $reason, ?int $userId): void
    {
        $reason = trim($reason);
        $length = mb_strlen($reason);

        if ($length < 10 || $length > 500) {
            throw ValidationException::withMessages([
                'override_reason' => 'Override reason must be between 10 and 500 characters.',
            ]);
        }

        $address->override_reason = $reason;
        $address->manually_overridden = true;
        $address->requires_verification = true;
        $address->source_locked = true;

        $changes = [];

        foreach (self::HIERARCHICAL_FIELDS as $field) {
            if (! array_key_exists($field, $incoming)) {
                continue;
            }

            $original = $address->getOriginal($field);
            $current = $address->{$field};

            if ($this->valuesMatch($original, $current)) {
                continue;
            }

            $changes[$field] = [
                'old' => $this->sanitizeValue($original),
                'new' => $this->sanitizeValue($current),
            ];
        }

        if ($changes !== []) {
            AddressAudit::create([
                'address_id' => $address->id,
                'user_id' => $userId,
                'action' => 'override',
                'changed_fields' => $changes,
                'override_reason' => $reason,
                'created_at' => now(),
            ]);
        }
    }

    private function valuesMatch(mixed $first, mixed $second): bool
    {
        if ($first === null && $second === null) {
            return true;
        }

        return trim((string) $first) === trim((string) $second);
    }

    private function sanitizeValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $string = (string) $value;

        if (! mb_check_encoding($string, 'UTF-8')) {
            $string = utf8_encode($string);
        }

        return $string;
    }

    /**
     * @param  array<int, string>  $lockedFields
     */
    public static function canWrite(string $field, array $lockedFields): bool
    {
        if ($lockedFields === []) {
            return true;
        }

        $key = trim($field);

        if ($key === '') {
            return true;
        }

        return ! in_array($key, $lockedFields, true);
    }
}
