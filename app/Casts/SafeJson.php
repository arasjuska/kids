<?php

declare(strict_types=1);

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

class SafeJson implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        if ($value === null || $value === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return $decoded ?? [];
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            $value = $this->sanitizeString($value);

            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        }

        if (is_object($value)) {
            $value = method_exists($value, 'jsonSerialize')
                ? $value->jsonSerialize()
                : (array) $value;
        }

        if (is_array($value)) {
            $value = $this->sanitizeArray($value);
        }

        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        return $encoded === false ? '[]' : $encoded;
    }

    private function sanitizeArray(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->sanitizeArray($item);
                continue;
            }

            if (is_string($item)) {
                $value[$key] = $this->sanitizeString($item);
            }
        }

        return $value;
    }

    private function sanitizeString(string $value): string
    {
        if (! mb_check_encoding($value, 'UTF-8')) {
            $converted = @mb_convert_encoding($value, 'UTF-8', 'UTF-8');

            if ($converted === false) {
                $converted = @iconv('UTF-8', 'UTF-8//IGNORE', $value);
            }

            $value = $converted === false ? '' : $converted;
        }

        if ($value === '') {
            return '';
        }

        return preg_replace('/[^\P{C}\n\t]/u', '', $value) ?? '';
    }
}
