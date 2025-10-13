<?php

namespace App\Enums;

enum AddressTypeEnum: string
{
    case VERIFIED = 'verified';           // Patvirtintas per geocoding API
    case LOW_CONFIDENCE = 'low_confidence'; // Žemas patikimumo lygis
    case UNVERIFIED = 'unverified';       // Rankinis įvedimas, nepatvirtintas
    case VIRTUAL = 'virtual';             // Virtualus adresas gamtoje

    public function label(): string
    {
        return match ($this) {
            self::VERIFIED => 'Patvirtintas adresas',
            self::LOW_CONFIDENCE => 'Žemo patikimumo adresas',
            self::UNVERIFIED => 'Nepatvirtintas adresas',
            self::VIRTUAL => 'Virtualus adresas',
        };
    }

    public function getConfidenceLevel(): float
    {
        return match ($this) {
            self::VERIFIED => 0.95,
            self::LOW_CONFIDENCE => 0.6,
            self::UNVERIFIED => 0.3,
            self::VIRTUAL => 0.0,
        };
    }

    public function needsValidation(): bool
    {
        return in_array($this, [self::LOW_CONFIDENCE, self::UNVERIFIED]);
    }

    public function getBadgeColor(): string
    {
        return match ($this) {
            self::VERIFIED => 'success',
            self::LOW_CONFIDENCE => 'warning',
            self::UNVERIFIED => 'danger',
            self::VIRTUAL => 'info',
        };
    }
}
