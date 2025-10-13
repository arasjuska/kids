<?php

namespace App\Enums;

enum InputModeEnum: string
{
    case SEARCH = 'search';
    case MANUAL = 'manual';
    case MIXED = 'mixed'; // Pradėjo su search, bet perjungė į manual

    public function label(): string
    {
        return match ($this) {
            self::SEARCH => 'Paieškos režimas',
            self::MANUAL => 'Rankinis įvedimas',
            self::MIXED => 'Mišrus režimas',
        };
    }

    public function allowsSearch(): bool
    {
        return in_array($this, [self::SEARCH, self::MIXED]);
    }

    public function allowsManualInput(): bool
    {
        return in_array($this, [self::MANUAL, self::MIXED]);
    }
}
