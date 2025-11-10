<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class Utf8String implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if (! is_string($value)) {
            return;
        }

        if (! mb_check_encoding($value, 'UTF-8')) {
            $fail(__('Neatitinka UTF-8 standarto. Prašome pakartoti įvestį.'));

            return;
        }

        $clean = @iconv('UTF-8', 'UTF-8//IGNORE', $value);
        if ($clean === false || $clean !== $value) {
            $fail(__('Rasti neleistini simboliai. Įveskite reikšmę dar kartą.'));

            return;
        }

        if (preg_match('/[^\P{C}\n\t]/u', $value)) {
            $fail(__('Rasti neleistini valdymo simboliai. Įveskite reikšmę dar kartą.'));
        }
    }
}
