<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use App\Support\TextNormalizer;

trait NormalizesUtf8Input
{
    /**
     * Normalize defined fields on the current request instance.
     *
     * @param  array<int, string>  $fields
     * @return array<string, string|null>
     */
    protected function normalizeInputFields(array $fields): array
    {
        $normalized = [];

        foreach ($fields as $field) {
            if (! $this->exists($field)) {
                continue;
            }

            $value = $this->input($field);

            if ($value !== null && ! is_string($value)) {
                continue;
            }

            $normalized[$field] = TextNormalizer::toNfc($value);
        }

        return $normalized;
    }

    /**
     * Normalize the provided payload array for the given fields.
     *
     * @param  array<int, string>  $fields
     * @return array<string, mixed>
     */
    protected static function normalizeArrayValues(array $payload, array $fields): array
    {
        foreach ($fields as $field) {
            if (! array_key_exists($field, $payload)) {
                continue;
            }

            $value = $payload[$field];

            if ($value !== null && ! is_string($value)) {
                continue;
            }

            $payload[$field] = TextNormalizer::toNfc($value);
        }

        return $payload;
    }
}
