<?php

namespace App\Enums;

enum AddressStateEnum: string
{
    case IDLE = 'idle';              // laukiam įvedimo
    case SEARCHING = 'searching';    // vyksta paieška
    case SUGGESTIONS = 'suggestions';// rodom pasiūlymus
    case NO_RESULTS = 'no_results';  // paieška negrąžino rezultatų
    case MANUAL = 'manual';          // rankinis redagavimas
    case CONFIRMED = 'confirmed';    // pasirinktas galutinis adresas
    case ERROR = 'error';

    public function label(): string
    {
        return match ($this) {
            self::IDLE => 'Laukiama įvesties',
            self::SEARCHING => 'Ieškoma...',
            self::SUGGESTIONS => 'Rodyti pasiūlymai',
            self::NO_RESULTS => 'Rezultatų nerasta',
            self::MANUAL => 'Rankinis režimas',
            self::CONFIRMED => 'Adresas patvirtintas',
            self::ERROR => 'Klaida',
        };
    }
}
