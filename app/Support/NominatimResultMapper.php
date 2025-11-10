<?php

declare(strict_types=1);

namespace App\Support;

final class NominatimResultMapper
{
    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, ?string>
     */
    public static function toAddressArray(array $raw): array
    {
        $address = $raw['address'] ?? [];

        $city = $address['city']
            ?? $address['town']
            ?? $address['village']
            ?? $address['hamlet']
            ?? $address['suburb']
            ?? null;

        return [
            'street' => self::sanitize($address['road'] ?? $address['pedestrian'] ?? $address['footway'] ?? null),
            'house_number' => self::sanitize($address['house_number'] ?? null),
            'city' => self::sanitize($city),
            'postcode' => self::sanitize($address['postcode'] ?? null),
            'municipality' => self::sanitize($address['municipality'] ?? $address['county'] ?? null),
            'region' => self::sanitize($address['state'] ?? null),
            'country' => self::sanitize($address['country'] ?? 'Lithuania'),
            'country_code' => self::sanitize($address['country_code'] ?? null),
        ];
    }

    private static function sanitize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $clean = @iconv('UTF-8', 'UTF-8//IGNORE', $value);
        $clean = $clean === false ? null : $clean;

        if ($clean === null) {
            return null;
        }

        $trimmed = trim($clean);

        return $trimmed === '' ? null : $trimmed;
    }
}
