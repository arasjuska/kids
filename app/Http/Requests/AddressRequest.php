<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizesUtf8Input;
use Illuminate\Foundation\Http\FormRequest;

class AddressRequest extends FormRequest
{
    use NormalizesUtf8Input;

    private const NFC_FIELDS = [
        'formatted_address',
        'short_address_line',
        'street_name',
        'street_number',
        'city',
        'state',
        'postal_code',
        'country',
        'country_code',
        'description',
        'override_reason',
        'provider',
        'provider_place_id',
        'osm_type',
    ];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [];
    }

    protected function prepareForValidation(): void
    {
        $this->merge($this->normalizeInputFields(self::NFC_FIELDS));
    }

    /**
     * Normalize a plain payload without requiring a bound HTTP request.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function normalizePayload(array $payload): array
    {
        return self::normalizeArrayValues($payload, self::NFC_FIELDS);
    }
}
