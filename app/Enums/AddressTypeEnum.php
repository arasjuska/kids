<?php

declare(strict_types=1);

namespace App\Enums;

// PERVADINTA: Pavadinimas AddressTypeEnum geriau atspindi klasės paskirtį
enum AddressTypeEnum: string
{
    case VERIFIED = 'verified';           // Patvirtintas per geocoding API, aukštas patikimumas
    case LOW_CONFIDENCE = 'low_confidence'; // Geokoduotas, bet žemas patikimumo lygis
    case UNVERIFIED = 'unverified';       // Rankinis įvedimas, nepatvirtintas
    case VIRTUAL = 'virtual';             // Virtualus adresas (koordinatės lauke/jūroje)

    public function label(): string
    {
        return match ($this) {
            self::VERIFIED => 'Patvirtintas (Tikslus)',
            self::LOW_CONFIDENCE => 'Žemas patikimumas',
            self::UNVERIFIED => 'Nepatvirtintas (Rankinis)',
            self::VIRTUAL => 'Virtualus adresas',
        };
    }

    /**
     * Grąžina boolean reikšmę, skirtą įrašymui į 'is_verified' modelio stulpelį.
     */
    public function isVerified(): bool
    {
        return $this === self::VERIFIED;
    }

    /**
     * Grąžina patikimumo lygį (naudojamas tik vizualizacijai ir UI logikai).
     */
    public function getConfidenceLevel(): float
    {
        return match ($this) {
            self::VERIFIED => 0.95,
            self::LOW_CONFIDENCE => 0.6,
            self::UNVERIFIED => 0.3,
            self::VIRTUAL => 0.0,
        };
    }

    /**
     * Nurodo, ar adresui reikalinga peržiūra ar papildoma validacija.
     */
    public function needsValidation(): bool
    {
        return in_array($this, [self::LOW_CONFIDENCE, self::UNVERIFIED, self::VIRTUAL]);
    }

    /**
     * Grąžina spalvą, tinkamą Filament/Tailwind badge komponentui.
     */
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
