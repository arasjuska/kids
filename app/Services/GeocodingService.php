<?php

namespace App\Services;

use App\Contracts\GeocodingServiceInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class GeocodingService implements GeocodingServiceInterface
{
    // PATAISYTOS KONSTANTOS - suderinta su realiais rezultatais
    private const CONFIDENCE_BASE = 0.4;           // Sumažinta bazė
    private const CONFIDENCE_HAS_STREET = 0.25;    // Padidinta gatvės svarba
    private const CONFIDENCE_HAS_NUMBER = 0.35;    // Padidinta numerio svarba
    private const CONFIDENCE_IS_BUILDING = 0.1;    // Šiek tiek sumažinta
    private const CONFIDENCE_SINGLE_RESULT_BOOST = 0.15; // Palikta

    protected string $baseUrl = 'https://nominatim.openstreetmap.org';
    protected int $cacheMinutes = 1440; // 24 hours

    public function search(string $query, array $options = []): Collection
    {
        // PATAISYMAS: Pridėkite versiją į cache raktą, kad senas cache nebūtų naudojamas
        $cacheKey = 'geocoding:search:v2:' . md5($query . serialize($options));

        return Cache::remember($cacheKey, $this->cacheMinutes, function () use ($query, $options) {
            try {
                $this->respectRateLimit();

                $response = Http::timeout(10)
                    ->withHeaders(['User-Agent' => config('app.name', 'Laravel App')])
                    ->get($this->baseUrl . '/search', array_merge([
                        'q' => $query,
                        'format' => 'jsonv2',
                        'addressdetails' => 1,
                        'limit' => $options['limit'] ?? 5,
                        'countrycodes' => $options['country_codes'] ?? 'lt',
                        'accept-language' => $options['language'] ?? 'lt,en',
                    ], $options));

                if (!$response->successful()) {
                    Log::warning('Geocoding search failed', [
                        'query' => $query,
                        'status' => $response->status(),
                        'response' => $response->body()
                    ]);
                    return collect();
                }

                $results = collect($response->json());

                // Pašaliname dublikatus pagal place_id
                $uniqueResults = $results->unique('place_id');

                // Filtruojame pagal adreso kokybę
                $filteredResults = $this->filterUniqueAddresses($uniqueResults);

                // Apdorojame rezultatus ir apskaičiuojame confidence
                $processedResults = $filteredResults->map(function ($item) use ($query) {
                    return $this->processSearchResult($item, $query);
                });

                // NAUJAS FILTRAS: Jei yra daug rezultatų su skirtingais miestais,
                // bet vienas rezultatas yra iš pirmo miesto - boost'iname jį
                if ($processedResults->count() > 1) {
                    $processedResults = $this->applyGeographicPriorityBoost($processedResults);
                }

                // DEBUG: Log'iname pradinį confidence
                Log::debug('Geocoding search processed', [
                    'query' => $query,
                    'results_count' => $processedResults->count(),
                    'first_result_confidence' => $processedResults->first()['confidence'] ?? null,
                    'first_result_data' => $processedResults->first() ?? null,
                ]);

                // SVARBU: Jei vienas rezultatas - boost confidence
                if ($processedResults->count() === 1) {
                    $processedResults = $processedResults->map(function ($item) {
                        $originalConfidence = $item['confidence'];
                        $newConfidence = min(1.0, $item['confidence'] + self::CONFIDENCE_SINGLE_RESULT_BOOST);

                        // PAPILDOMAS BOOST: Jei turi gatvę + numerį, garantuojame VERIFIED
                        if (!empty($item['street_name']) && !empty($item['street_number'])) {
                            $newConfidence = max(0.95, $newConfidence);
                        }

                        $item['confidence'] = $newConfidence;

                        // DEBUG: Log'iname confidence boost'ą
                        Log::debug('Single result confidence boost', [
                            'street' => $item['street_name'] ?? 'N/A',
                            'number' => $item['street_number'] ?? 'N/A',
                            'original_confidence' => $originalConfidence,
                            'boosted_confidence' => $newConfidence,
                        ]);

                        return $item;
                    });
                }

                // Rikiuojame pagal confidence (aukščiausias pirmasis)
                return $processedResults->sortByDesc('confidence')->values();
            } catch (\Exception $e) {
                Log::error('Geocoding search error', [
                    'query' => $query,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                return collect();
            }
        });
    }

    private function filterUniqueAddresses(Collection $results): Collection
    {
        $uniqueAddresses = [];

        return $results->filter(function ($item) use (&$uniqueAddresses) {
            $address = $item['address'] ?? [];

            // Sukuriame unikalumo raktą
            $parts = array_filter([
                $address['road'] ?? '',
                $address['house_number'] ?? '',
                $address['postcode'] ?? '',
                $address['city'] ?? $address['town'] ?? $address['village'] ?? '',
            ]);

            // Minimumas - turi būti gatvė arba miestas + pašto kodas
            if (empty($address['road']) && count($parts) < 2) {
                return false;
            }

            // Unikalumo raktas
            $key = md5(mb_strtolower(implode('|', $parts)));

            if (isset($uniqueAddresses[$key])) {
                return false;
            }

            $uniqueAddresses[$key] = true;
            return true;
        });
    }

    private function processSearchResult(array $item, string $originalQuery): array
    {
        $address = $item['address'] ?? [];

        $streetName = $address['road'] ?? '';
        $streetNumber = $address['house_number'] ?? '';
        $city = $address['city']
            ?? $address['town']
            ?? $address['village']
            ?? $address['hamlet']
            ?? $address['municipality']
            ?? null;

        // Sukuriame trumpą adreso eilutę
        $shortAddress = $this->buildShortAddress($streetName, $streetNumber);

        // Konteksto eilutė (pašto kodas, miestas)
        $contextLine = $this->buildContextLine($address['postcode'] ?? '', $city);

        // APSKAIČIUOJAME CONFIDENCE
        $confidence = $this->calculateConfidence($item, $address, $originalQuery);

        return [
            'short_address_line' => $shortAddress,
            'context_line' => $contextLine,
            'formatted_address' => $item['display_name'] ?? '',
            'latitude' => (float) ($item['lat'] ?? 0),
            'longitude' => (float) ($item['lon'] ?? 0),
            'place_id' => $item['place_id'] ?? null,
            'street_name' => $streetName ?: null,
            'street_number' => $streetNumber ?: null,
            'city' => $city,
            'state' => $address['state'] ?? null,
            'postal_code' => $address['postcode'] ?? null,
            'country' => $address['country'] ?? null,
            'country_code' => strtoupper($address['country_code'] ?? 'LT'),
            'confidence' => $confidence,
            // Debug info (optional)
            'osm_type' => $item['osm_type'] ?? null,
            'osm_class' => $item['class'] ?? null,
            'osm_type_detail' => $item['type'] ?? null,
        ];
    }

    private function calculateConfidence(array $item, array $address, string $query): float
    {
        $confidence = self::CONFIDENCE_BASE;

        // +0.25 jei turi gatvės pavadinimą
        if (!empty($address['road'])) {
            $confidence += self::CONFIDENCE_HAS_STREET;
        }

        // +0.35 jei turi namo numerį (SVARBIAUSIAS FAKTORIUS)
        if (!empty($address['house_number'])) {
            $confidence += self::CONFIDENCE_HAS_NUMBER;
        }

        // +0.1 jei tai pastatas/namas
        if ($this->isBuilding($item)) {
            $confidence += self::CONFIDENCE_IS_BUILDING;
        }

        // Bonus už tikslų atitikimą
        $confidence += $this->calculateMatchBonus($item, $query);

        // SVARBU: Jei turi ir gatvę, ir numerį - garantuojame minimalų 0.85 balą
        if (!empty($address['road']) && !empty($address['house_number'])) {
            $confidence = max(0.85, $confidence);
        }

        // Baudžiame rezultatus be gatvės pavadinimo
        if (empty($address['road'])) {
            $confidence *= 0.5; // Sumažiname per pusę
        }

        return min(1.0, max(0.0, $confidence));
    }

    private function isBuilding(array $item): bool
    {
        $type = $item['type'] ?? '';
        $class = $item['class'] ?? '';
        $osmType = $item['osm_type'] ?? '';

        // Platesnis building tipų sąrašas
        return in_array($type, ['house', 'residential', 'apartments', 'building', 'detached', 'terrace'])
            || ($class === 'building' && in_array($osmType, ['way', 'node']))
            || ($class === 'place' && $type === 'house')
            || ($osmType === 'node' && $class === 'place' && $type === 'house');
    }

    private function calculateMatchBonus(array $item, string $query): float
    {
        $displayName = mb_strtolower($item['display_name'] ?? '');
        $queryLower = mb_strtolower(trim($query));

        // Tikslus atitikimas pradžioje
        if (str_starts_with($displayName, $queryLower)) {
            return 0.1;
        }

        // Dalinis atitikimas
        if (str_contains($displayName, $queryLower)) {
            return 0.05;
        }

        // Trumpas rezultatas (konkretesnis)
        if (strlen($displayName) < 80) {
            return 0.03;
        }

        return 0.0;
    }

    private function buildShortAddress(string $street, string $number): string
    {
        if (empty($street) && empty($number)) {
            return '';
        }

        return trim($street . (!empty($number) ? ' ' . $number : ''));
    }

    private function buildContextLine(string $postalCode, ?string $city): string
    {
        $parts = array_filter([$postalCode, $city]);
        return implode(', ', $parts);
    }

    /**
     * Jei visi rezultatai turi tą patį gatvės pavadinimą + numerį,
     * bet skirtingus miestus, boost'iname pirmą (artimiausią pagal Nominatim ranking)
     */
    private function applyGeographicPriorityBoost(Collection $results): Collection
    {
        // Patikriname, ar visi rezultatai turi tą patį street_name + street_number
        $firstStreet = $results->first()['street_name'] ?? '';
        $firstNumber = $results->first()['street_number'] ?? '';

        if (empty($firstStreet) || empty($firstNumber)) {
            return $results;
        }

        $allSameStreet = $results->every(function ($item) use ($firstStreet, $firstNumber) {
            return ($item['street_name'] ?? '') === $firstStreet
                && ($item['street_number'] ?? '') === $firstNumber;
        });

        if (!$allSameStreet) {
            return $results;
        }

        // Visi rezultatai turi tą patį adresą, bet skirtingus miestus
        // Boost'iname pirmąjį rezultatą (Nominatim jau išrikiavo pagal relevance)
        return $results->map(function ($item, $index) {
            if ($index === 0 && !empty($item['street_name']) && !empty($item['street_number'])) {
                // Pirmam rezultatui suteikiame VERIFIED statusą
                $item['confidence'] = max(0.95, $item['confidence']);
            }
            return $item;
        });
    }

    public function reverse(float $lat, float $lng): ?object
    {
        // PATAISYMAS: Pridėkite versiją į cache raktą
        $cacheKey = "geocoding:reverse:v2:{$lat}:{$lng}";

        return Cache::remember($cacheKey, $this->cacheMinutes, function () use ($lat, $lng) {
            try {
                $this->respectRateLimit();

                $response = Http::timeout(10)
                    ->withHeaders(['User-Agent' => config('app.name', 'Laravel App')])
                    ->get($this->baseUrl . '/reverse', [
                        'lat' => $lat,
                        'lon' => $lng,
                        'format' => 'jsonv2',
                        'addressdetails' => 1,
                        'accept-language' => 'lt,en',
                    ]);

                if (!$response->successful()) {
                    Log::warning('Geocoding reverse failed', [
                        'lat' => $lat,
                        'lng' => $lng,
                        'status' => $response->status(),
                    ]);
                    return null;
                }

                $data = $response->json();
                $address = $data['address'] ?? [];

                $city = $address['city']
                    ?? $address['town']
                    ?? $address['village']
                    ?? $address['hamlet']
                    ?? $address['municipality']
                    ?? null;

                // Apskaičiuojame confidence reverse rezultatui
                $confidenceScore = 0.5; // Bazinė vertė reverse

                if (!empty($address['road'])) {
                    $confidenceScore += 0.25;
                }

                if (!empty($address['house_number'])) {
                    $confidenceScore += 0.25;
                }

                // Jei turi abu - garantuojame aukštą confidence
                if (!empty($address['road']) && !empty($address['house_number'])) {
                    $confidenceScore = max(0.9, $confidenceScore);
                }

                return (object) [
                    'formatted_address' => $data['display_name'] ?? '',
                    'latitude' => (float) ($data['lat'] ?? 0),
                    'longitude' => (float) ($data['lon'] ?? 0),
                    'street_name' => $address['road'] ?? null,
                    'street_number' => $address['house_number'] ?? null,
                    'city' => $city,
                    'state' => $address['state'] ?? null,
                    'postal_code' => $address['postcode'] ?? null,
                    'country' => $address['country'] ?? null,
                    'country_code' => strtoupper($address['country_code'] ?? 'LT'),
                    'provider' => 'nominatim',
                    'confidence_score' => min(1.0, $confidenceScore),
                    'address_type' => $data['type'] ?? null,
                ];
            } catch (\Exception $e) {
                Log::error('Reverse geocoding error', [
                    'lat' => $lat,
                    'lng' => $lng,
                    'error' => $e->getMessage()
                ]);
                return null;
            }
        });
    }

    public function isAvailable(): bool
    {
        try {
            $response = Http::timeout(5)
                ->withHeaders(['User-Agent' => config('app.name', 'Laravel App')])
                ->get($this->baseUrl . '/search', [
                    'q' => 'Lithuania',
                    'limit' => 1,
                    'format' => 'json'
                ]);

            return $response->successful();
        } catch (\Exception $e) {
            Log::warning('Nominatim availability check failed', [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    public function getProviderName(): string
    {
        return 'nominatim';
    }

    protected function respectRateLimit(): void
    {
        $lastRequest = Cache::get('nominatim_last_request', 0);
        $timeSinceLastRequest = microtime(true) - $lastRequest;

        // Nominatim requires 1 request per second
        if ($timeSinceLastRequest < 1.0) {
            usleep((int) ((1.0 - $timeSinceLastRequest) * 1000000));
        }

        Cache::put('nominatim_last_request', microtime(true), 60);
    }
}
