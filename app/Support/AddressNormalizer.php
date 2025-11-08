<?php

namespace App\Support;

final class AddressNormalizer
{
    /** @var array<string,string> */
    private array $abbr = [
        'g.' => 'gatvė',
        'pr.' => 'prospektas',
        'al.' => 'alėja',
        'pl.' => 'plentas',
        'kel.' => 'kelias',
        'skg.' => 'skersgatvis',
    ];

    public function normalizeStreet(?string $street): ?string
    {
        if ($street === null) {
            return null;
        }

        $street = $this->collapse($street);
        $street = mb_strtolower($street);

        foreach ($this->abbr as $from => $to) {
            $pattern = '/(?<=^|\s)'.preg_quote($from, '/').'(?=\s|$)/u';
            $street = preg_replace($pattern, $to, $street);
        }

        $street = preg_replace('/[^\p{L}\p{N}\/\s]/u', '', $street);
        $street = trim(preg_replace('/\s+/u', ' ', $street) ?? '');

        return $street === '' ? null : $street;
    }

    public function normalizeNumber(?string $number): ?string
    {
        if ($number === null) {
            return null;
        }

        $number = $this->collapse($number);
        $number = preg_replace('/\s*-\s*/', '/', $number);
        $number = mb_strtolower($number ?? '');
        $number = preg_replace('/[^\p{L}\p{N}\/]/u', '', $number ?? '');
        $number = trim($number, '/');

        return $number === '' ? null : $number;
    }

    /**
     * @param  array{street_name:?string,street_number:?string,city:?string,country_code:?string,postal_code?:?string}  $parts
     */
    public function canonical(array $parts): string
    {
        $street = $this->normalizeStreet($parts['street_name'] ?? null);
        $number = $this->normalizeNumber($parts['street_number'] ?? null);
        $city = $this->base($parts['city'] ?? null);
        $country = $this->upper($parts['country_code'] ?? null);
        $postal = $this->base($parts['postal_code'] ?? null);

        $filtered = array_filter([
            $street,
            $number,
            $city,
            $country,
            $postal,
        ], fn ($value) => $value !== null && $value !== '');

        if (empty($filtered)) {
            return '';
        }

        $joined = implode('|', $filtered);

        return $this->toAscii($joined);
    }

    /**
     * @param  array{street_name:?string,street_number:?string,city:?string,country_code:?string,postal_code?:?string}  $parts
     */
    public function signature(array $parts): ?string
    {
        $canonical = $this->canonical($parts);

        if ($canonical === '') {
            return null;
        }

        return hash('sha256', $canonical, true);
    }

    private function base(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = mb_strtolower($this->collapse($value));
        $value = preg_replace('/[^\p{L}\p{N}\/\s]/u', '', $value);
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');

        return $value === '' ? null : $value;
    }

    private function upper(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = strtoupper($this->collapse($value));
        $value = preg_replace('/[^A-Z]/', '', $value);

        return $value === '' ? null : $value;
    }

    private function collapse(string $value): string
    {
        $value = trim($value);

        return preg_replace('/\s+/u', ' ', $value) ?? '';
    }

    private function toAscii(string $value): string
    {
        if (class_exists(\Transliterator::class)) {
            $transliterator = \Transliterator::create('NFD; [:Nonspacing Mark:] Remove; NFC; Latin-ASCII');
            if ($transliterator) {
                $value = $transliterator->transliterate($value);
            }
        } else {
            $value = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value;
        }

        return trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    }
}
