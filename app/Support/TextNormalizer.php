<?php

declare(strict_types=1);

namespace App\Support;

final class TextNormalizer
{
    public static function toNfc(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $sanitized = self::sanitizeUtf8($value);

        if ($sanitized === '') {
            return '';
        }

        if (class_exists(\Normalizer::class)) {
            $normalized = \Normalizer::normalize($sanitized, \Normalizer::FORM_C);

            if ($normalized !== false) {
                return $normalized;
            }
        }

        return $sanitized;
    }

    private static function sanitizeUtf8(string $value): string
    {
        $clean = @iconv('UTF-8', 'UTF-8//IGNORE', $value);

        if ($clean === false && function_exists('mb_convert_encoding')) {
            $originalSubstitute = null;

            if (function_exists('mb_substitute_character')) {
                $originalSubstitute = mb_substitute_character();
                mb_substitute_character('none');
            }

            $clean = @mb_convert_encoding($value, 'UTF-8', 'UTF-8');

            if ($originalSubstitute !== null) {
                mb_substitute_character($originalSubstitute);
            }
        }

        if ($clean === false || $clean === null) {
            $clean = '';
        }

        $clean = preg_replace('/[^\P{C}\n\t]/u', '', $clean) ?? '';

        return $clean;
    }
}
