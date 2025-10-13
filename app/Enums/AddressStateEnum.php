<?php

namespace App\Enums;

enum AddressStateEnum: string
{
    case IDLE = 'idle';
    case SEARCHING = 'searching';
    case SUGGESTIONS = 'suggestions';
    case CONFIRMED = 'confirmed';
    case MANUAL = 'manual';
    case ERROR = 'error';

    public function label(): string
    {
        return match ($this) {
            self::IDLE => 'Laukiama įvesties',
            self::SEARCHING => 'Ieškoma...',
            self::SUGGESTIONS => 'Rodyti pasiūlymai',
            self::CONFIRMED => 'Adresas patvirtintas',
            self::MANUAL => 'Rankinis režimas',
            self::ERROR => 'Klaida',
        };
    }

    public function canShowMap(): bool
    {
        return in_array($this, [self::CONFIRMED, self::MANUAL]);
    }

    public function canShowSuggestions(): bool
    {
        return $this === self::SUGGESTIONS;
    }

    public function canShowManualFields(): bool
    {
        return $this === self::MANUAL;
    }

    public function isLoading(): bool
    {
        return $this === self::SEARCHING;
    }
}
