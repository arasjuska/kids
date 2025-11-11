<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizesUtf8Input;
use Illuminate\Foundation\Http\FormRequest;

class ActivityRequest extends FormRequest
{
    use NormalizesUtf8Input;

    private const NFC_FIELDS = [
        'title',
        'description',
        'location',
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
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function normalizePayload(array $payload): array
    {
        return self::normalizeArrayValues($payload, self::NFC_FIELDS);
    }
}
