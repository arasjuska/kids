<?php

namespace App\Services;

use App\Contracts\GeocodingServiceInterface;
use Exception;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Geokodavimo paslauga, naudojanti OpenStreetMap Nominatim API.
 * Apima išplėstinę rezultatų filtravimo ir patikimumo (Confidence) logiką.
 */
class GeocodingService implements GeocodingServiceInterface
{
    // Konfidencialumo balų konstantos perkeltos į config failą,
    // bet paliekamos čia, jei jos yra susijusios tik su šiuo servisu.
    // Jei ateityje bus daugiau Geocoding Service implementacijų, perkelti į config.
    private const CONFIDENCE_BASE = 0.4;
    private const CONFIDENCE_HAS_STREET = 0.25;
    private const CONFIDENCE_HAS_NUMBER = 0.35;
    private const CONFIDENCE_IS_BUILDING = 0.1;
    private const CONFIDENCE_SINGLE_RESULT_BOOST = 0.15;

    // PATAISYMAS: Nominatim URL, cache laikas imami iš Laravel config.
    protected string $baseUrl;
    protected int $cacheMinutes;
    protected string $defaultCountryCode;
    protected int $connectTimeout;
    protected int $requestTimeout;
    protected int $retryAttempts;
    protected int $retryBackoffBaseMs;
    protected int $circuitThreshold;
    protected int $circuitCooldown;

    public function __construct()
    {
        // Naudojame config() funkcijas, kad būtų galima konfigūruoti per .env ar config/services.php
        $this->baseUrl = config('services.nominatim.url', 'https://nominatim.openstreetmap.org');
        $this->cacheMinutes = (int) config('services.nominatim.cache_duration', 1440);
        $this->defaultCountryCode = config('services.nominatim.default_country', 'lt');
        $this->connectTimeout = max(1, (int) config('services.nominatim.connect_timeout', 2));
        $this->requestTimeout = max($this->connectTimeout, (int) config('services.nominatim.timeout', 4));
        $this->retryAttempts = max(1, (int) config('services.nominatim.retries', 3));
        $this->retryBackoffBaseMs = max(50, (int) config('services.nominatim.retry_backoff_ms', 200));
        $this->circuitThreshold = max(1, (int) config('services.nominatim.circuit_threshold', 5));
        $this->circuitCooldown = max(10, (int) config('services.nominatim.circuit_cooldown', 60));
    }

    public function search(string $query, array $options = []): Collection
    {
        $normalizedQuery = $this->normalizeQuery($query);

        if (mb_strlen($normalizedQuery) < 3) {
            return collect();
        }

        $normalizedOptions = $this->normalizeSearchOptions($options);
        $cacheKey = $this->buildSearchCacheKey($normalizedQuery, $normalizedOptions);

        return Cache::remember($cacheKey, now()->addMinutes($this->cacheMinutes), function () use ($normalizedQuery, $normalizedOptions) {
            if ($this->isCircuitOpen()) {
                Log::notice('Geocoding search skipped due to open circuit breaker');
                return collect();
            }

            try {
                $this->respectRateLimit();

                // NAUJOVĖ: Imame numatytąsias vertes iš konstruktoriaus savybių (config)
                $countryCodes = $normalizedOptions['countrycodes'] ?? $this->defaultCountryCode;
                $language = $normalizedOptions['accept-language'] ?? 'lt,en';

                $response = $this->http()->get($this->baseUrl . '/search', array_merge([
                    'q' => $normalizedQuery,
                    'format' => 'jsonv2',
                    'addressdetails' => 1,
                    'limit' => $normalizedOptions['limit'] ?? 5,
                    'countrycodes' => $countryCodes,
                    'accept-language' => $language,
                ], Arr::except($normalizedOptions, ['limit', 'countrycodes', 'accept-language'])));

                if (!$response->successful()) {
                    $this->recordFailure($response->status());
                    Log::warning('Geocoding search failed', [
                        'query' => $normalizedQuery,
                        'status' => $response->status(),
                        'response' => $response->body()
                    ]);
                    return $this->maybeFakeResults($normalizedQuery);
                }

                $this->recordSuccess();

                $results = collect($response->json());

                // Pašaliname dublikatus pagal place_id
                $uniqueResults = $results->unique('place_id');

                // Filtruojame pagal adreso kokybę
                $filteredResults = $this->filterUniqueAddresses($uniqueResults);

                // Apdorojame rezultatus ir apskaičiuojame confidence
                $processedResults = $filteredResults->map(fn($item) => $this->processSearchResult($item, $normalizedQuery));

                // Jei yra daug rezultatatų su skirtingais miestais, bet vienas yra geras - boost'iname jį
                if ($processedResults->count() > 1) {
                    $processedResults = $this->applyGeographicPriorityBoost($processedResults);
                }

                Log::debug('Geocoding search processed', [
                    'query' => $normalizedQuery,
                    'results_count' => $processedResults->count(),
                    'first_result_confidence' => $processedResults->first()['confidence'] ?? null,
                ]);

                // SVARBU: Jei vienas rezultatas - boost confidence (garantuoja didelį patikimumą)
                if ($processedResults->count() === 1) {
                    $processedResults = $processedResults->map(function ($item) {
                        $newConfidence = min(1.0, $item['confidence'] + self::CONFIDENCE_SINGLE_RESULT_BOOST);

                        // PAPILDOMAS BOOST: Jei turi gatvę + numerį, garantuojame VERIFIED
                        if (!empty($item['street_name']) && !empty($item['street_number'])) {
                            $newConfidence = max(0.95, $newConfidence);
                        }

                        $item['confidence'] = $newConfidence;

                        Log::debug('Single result confidence boost applied', [
                            'boosted_confidence' => $newConfidence,
                        ]);

                        return $item;
                    });
                }

                // Rikiuojame pagal confidence (aukščiausias pirmasis)
                return $processedResults->sortByDesc('confidence')->values();
            } catch (Exception $e) {
                // NAUJOVĖ: Patikriname, ar klaida yra API limito viršijimas
                $isRateLimit = str_contains($e->getMessage(), '429');

                $this->recordFailure($isRateLimit ? 429 : null);

                Log::error('Geocoding search error', [
                    'query' => $normalizedQuery,
                    'error' => $e->getMessage(),
                    'rate_limit_suspected' => $isRateLimit,
                ]);
                return $this->maybeFakeResults($normalizedQuery);
            }
        });
    }

    /**
     * Laikomės Nominatim 1 request/sekundę limito.
     * Naudojama paprasta blokavimo logika su cache.
     */
    protected function respectRateLimit(): void
    {
        $cacheKey = 'nominatim_last_request';
        $lastRequest = Cache::get($cacheKey, 0);
        $timeSinceLastRequest = microtime(true) - $lastRequest;

        if ($timeSinceLastRequest < 1.0) {
            // Laukia likusį laiką iki 1 sekundės pabaigos
            usleep((int)((1.0 - $timeSinceLastRequest) * 1000000));
        }

        // Išsaugo naują užklausos laiką, kad kiti procesai matytų
        Cache::put($cacheKey, microtime(true), 60);
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

            // Minimalus reikalavimas
            if (empty($address['road']) && count($parts) < 2) {
                return false;
            }

            // Unikalumo raktas, kad eliminuoti dublikatus, turinčius skirtingus place_id
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

        // Naudojame masyvo sintaksę vietoj daug ??, kad būtų kompaktiškiau
        $city = $address['city'] ?? $address['town'] ?? $address['village'] ?? $address['hamlet'] ?? $address['municipality'] ?? null;

        $shortAddress = $this->buildShortAddress($streetName, $streetNumber);
        $contextLine = $this->buildContextLine($address['postcode'] ?? '', $city);
        $confidence = $this->calculateConfidence($item, $address, $originalQuery);

        return [
            'short_address_line' => $shortAddress,
            'context_line' => $contextLine,
            'formatted_address' => $item['display_name'] ?? '',
            'latitude' => (float)($item['lat'] ?? 0),
            'longitude' => (float)($item['lon'] ?? 0),
            'place_id' => $item['place_id'] ?? null,
            'street_name' => $streetName ?: null,
            'street_number' => $streetNumber ?: null,
            'city' => $city,
            'state' => $address['state'] ?? null,
            'postal_code' => $address['postcode'] ?? null,
            'country' => $address['country'] ?? null,
            'country_code' => strtoupper($address['country_code'] ?? 'LT'),
            'confidence' => $confidence,
            // Debug info
            'osm_type' => $item['osm_type'] ?? null,
            'osm_class' => $item['class'] ?? null,
            'osm_type_detail' => $item['type'] ?? null,
        ];
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

    private function calculateConfidence(array $item, array $address, string $query): float
    {
        $confidence = self::CONFIDENCE_BASE;

        // Baziniai patikrinimai ir balai
        if (!empty($address['road'])) {
            $confidence += self::CONFIDENCE_HAS_STREET;
        }

        if (!empty($address['house_number'])) {
            $confidence += self::CONFIDENCE_HAS_NUMBER;
        }

        if ($this->isBuilding($item)) {
            $confidence += self::CONFIDENCE_IS_BUILDING;
        }

        $confidence += $this->calculateMatchBonus($item, $query);

        // Korekcijos ir minimalios garantijos
        if (!empty($address['road']) && !empty($address['house_number'])) {
            // Garantuoja, kad gerai suformuluotas adresas gaus aukštą balą
            $confidence = max(0.85, $confidence);
        }

        if (empty($address['road'])) {
            // Didelė bauda, jei nėra gatvės (tai tik miestas ar apylinkės)
            $confidence *= 0.5;
        }

        return min(1.0, max(0.0, $confidence));
    }

    private function isBuilding(array $item): bool
    {
        $type = $item['type'] ?? '';
        $class = $item['class'] ?? '';
        $osmType = $item['osm_type'] ?? '';

        // Detalus patikrinimas, ar rezultatas yra pastatas ar namas
        return in_array($type, ['house', 'residential', 'apartments', 'building', 'detached', 'terrace'])
            || ($class === 'building' && in_array($osmType, ['way', 'node']));
    }

    private function calculateMatchBonus(array $item, string $query): float
    {
        $displayName = mb_strtolower($item['display_name'] ?? '');
        $queryLower = mb_strtolower(trim($query));

        if (str_starts_with($displayName, $queryLower)) {
            return 0.1; // Geras atitikimas pradžioje
        }

        if (str_contains($displayName, $queryLower)) {
            return 0.05; // Dalinis atitikimas
        }

        // Mažas bonusas, jei rezultato ilgis trumpas (konkretesnis adresas)
        if (strlen($displayName) < 80) {
            return 0.03;
        }

        return 0.0;
    }

    /**
     * Boost'ina confidence, jei keli rezultatai turi tą patį gatvės/namo numerį,
     * bet skirtingus miestus, suteikiant prioritetą pirmajam (labiausiai relevantiam)
     */
    private function applyGeographicPriorityBoost(Collection $results): Collection
    {
        $firstResult = $results->first();
        $firstStreet = $firstResult['street_name'] ?? '';
        $firstNumber = $firstResult['street_number'] ?? '';

        if (empty($firstStreet) || empty($firstNumber)) {
            return $results; // Nėra pilno adreso informacijos, praleidžiame
        }

        // Tikriname, ar Visi adresai be miesto yra identiški
        $allSameStreetAndNumber = $results->every(fn($item) => ($item['street_name'] ?? '') === $firstStreet &&
            ($item['street_number'] ?? '') === $firstNumber
        );

        if (!$allSameStreetAndNumber) {
            return $results;
        }

        // Visi rezultatai turi tą patį fizinį adresą (tik miestas skiriasi)
        return $results->map(function ($item, $index) {
            if ($index === 0) {
                // Pirmam rezultatui (kuris yra aukščiausias pagal Nominatim) suteikiame aukštą balą
                $item['confidence'] = max(0.95, $item['confidence']);
            }
            return $item;
        });
    }

    public function reverse(float $lat, float $lng): ?object
    {
        $cacheKey = $this->buildReverseCacheKey($lat, $lng);

        return Cache::remember($cacheKey, now()->addMinutes($this->cacheMinutes), function () use ($lat, $lng) {
            if ($this->isCircuitOpen()) {
                Log::notice('Geocoding reverse skipped due to open circuit breaker');
                return $this->maybeFakeReverse($lat, $lng);
            }

            try {
                $this->respectRateLimit();

                $language = 'lt,en'; // Galima imti ir iš config

                $response = $this->http()->get($this->baseUrl . '/reverse', [
                    'lat' => $lat,
                    'lon' => $lng,
                    'format' => 'jsonv2',
                    'addressdetails' => 1,
                    'accept-language' => $language,
                ]);

                if (!$response->successful()) {
                    $this->recordFailure($response->status());
                    Log::warning('Geocoding reverse failed', [
                        'lat' => $lat,
                        'lng' => $lng,
                        'status' => $response->status(),
                    ]);
                    return $this->maybeFakeReverse($lat, $lng);
                }

                $this->recordSuccess();

                $data = $response->json();
                $address = $data['address'] ?? [];

                $city = $address['city'] ?? $address['town'] ?? $address['village'] ?? $address['hamlet'] ?? $address['municipality'] ?? null;

                $confidenceScore = 0.5;

                if (!empty($address['road'])) {
                    $confidenceScore += 0.25;
                }

                if (!empty($address['house_number'])) {
                    $confidenceScore += 0.25;
                }

                if (!empty($address['road']) && !empty($address['house_number'])) {
                    $confidenceScore = max(0.9, $confidenceScore);
                }

                // Grąžiname OBJEKTĄ, kad atitiktų GeocodingServiceInterface sąsają
                return (object)[
                    'formatted_address' => $data['display_name'] ?? '',
                    'latitude' => (float)($data['lat'] ?? 0),
                    'longitude' => (float)($data['lon'] ?? 0),
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
            } catch (Throwable $e) {
                $this->recordFailure();
                Log::error('Reverse geocoding error', [
                    'lat' => $lat,
                    'lng' => $lng,
                    'error' => $e->getMessage()
                ]);
                return $this->maybeFakeReverse($lat, $lng);
            }
        });
    }

    public function isAvailable(): bool
    {
        try {
            $response = Http::timeout(5)
                ->withHeaders(['User-Agent' => config('app.name', 'Laravel App')])
                ->get($this->baseUrl . '/search', [
                    'q' => 'Availability Check',
                    'limit' => 1,
                    'format' => 'json'
                ]);

            return $response->successful();
        } catch (Exception $e) {
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

    private function headers(): array
    {
        $ua = (string) config('services.nominatim.user_agent', config('app.name', 'Laravel App'));
        $email = (string) config('services.nominatim.email', '') ?: null;

        return array_filter([
            'User-Agent' => $ua,
            'Accept' => 'application/json',
            'From' => $email,
        ]);
    }

    private function normalizeQuery(string $query): string
    {
        $normalized = preg_replace('/\s+/', ' ', Str::lower(trim($query)));

        return $normalized === null ? '' : $normalized;
    }

    private function normalizeSearchOptions(array $options): array
    {
        $options = array_change_key_case($options, CASE_LOWER);

        if (isset($options['country_codes'])) {
            $options['countrycodes'] = Str::lower(str_replace(' ', '', (string) $options['country_codes']));
            unset($options['country_codes']);
        }

        if (isset($options['countrycodes'])) {
            $parts = array_filter(explode(',', $options['countrycodes']));
            $options['countrycodes'] = implode(',', array_map(fn ($code) => Str::lower(trim($code)), $parts));
        }

        if (isset($options['accept-language'])) {
            $options['accept-language'] = Str::lower(trim((string) $options['accept-language']));
        }

        if (isset($options['limit'])) {
            $options['limit'] = max(1, (int) $options['limit']);
        }

        ksort($options);

        return $options;
    }

    private function buildSearchCacheKey(string $query, array $options): string
    {
        return 'geocoding:search:v3:' . md5($query . '|' . json_encode($options, JSON_THROW_ON_ERROR));
    }

    private function buildReverseCacheKey(float $lat, float $lng): string
    {
        $latKey = sprintf('%.6F', $lat);
        $lngKey = sprintf('%.6F', $lng);

        return "geocoding:reverse:v3:{$latKey}:{$lngKey}";
    }

    private function http(): PendingRequest
    {
        return Http::withHeaders($this->headers())
            ->acceptJson()
            ->timeout($this->requestTimeout)
            ->connectTimeout($this->connectTimeout)
            ->retry(
                $this->retryAttempts,
                function (int $attempt) {
                    $backoff = $this->retryBackoffBaseMs * (2 ** max(0, $attempt - 1));

                    return $backoff + random_int(0, 100);
                },
                function ($exception) {
                    return $this->shouldRetry($exception);
                }
            );
    }

    private function shouldRetry($exception): bool
    {
        if ($exception instanceof ConnectionException) {
            return true;
        }

        if ($exception instanceof RequestException) {
            $response = $exception->response;
            $status = $response ? $response->status() : null;

            return $status === 429 || ($status !== null && $status >= 500);
        }

        return false;
    }

    private function isCircuitOpen(): bool
    {
        $openUntil = Cache::get($this->circuitCacheKey(), 0);
        $now = time();

        if ($openUntil > $now) {
            return true;
        }

        if ($openUntil > 0) {
            $this->closeCircuit();
        }

        return false;
    }

    private function recordFailure(?int $status = null): void
    {
        $key = $this->failureCountCacheKey();
        $count = Cache::increment($key);

        if (! $count) {
            $count = 1;
            Cache::put($key, $count, now()->addSeconds($this->circuitCooldown));
        } else {
            Cache::put($key, $count, now()->addSeconds($this->circuitCooldown));
        }

        if ($status === 429 || ($status !== null && $status >= 500) || $count >= $this->circuitThreshold) {
            $this->openCircuit();
        }
    }

    private function recordSuccess(): void
    {
        $this->closeCircuit();
    }

    private function openCircuit(): void
    {
        $openUntil = time() + $this->circuitCooldown;

        Cache::put($this->circuitCacheKey(), $openUntil, now()->addSeconds($this->circuitCooldown));
    }

    private function closeCircuit(): void
    {
        Cache::forget($this->circuitCacheKey());
        Cache::forget($this->failureCountCacheKey());
    }

    private function circuitCacheKey(): string
    {
        return 'geocoding:nominatim:circuit_open_until';
    }

    private function failureCountCacheKey(): string
    {
        return 'geocoding:nominatim:failure_count';
    }

    private function maybeFakeResults(string $query): Collection
    {
        $shouldFake = app()->hasDebugModeEnabled() && (bool) config('services.nominatim.fake_on_fail', false);
        if (! $shouldFake || mb_strlen(trim($query)) < 3) {
            return collect();
        }

        $seed = substr(md5($query), 0, 6);
        $baseLat = 54.8985; $baseLng = 23.9036; // Kaunas

        $fake = [
            [
                'short_address_line' => 'Laisvės al. 5',
                'context_line' => '44280 Kaunas',
                'formatted_address' => 'Laisvės al. 5, 44280 Kaunas, Lietuva',
                'latitude' => $baseLat + 0.001,
                'longitude' => $baseLng + 0.001,
                'place_id' => 'fake-'.$seed.'-1',
                'street_name' => 'Laisvės al.',
                'street_number' => '5',
                'city' => 'Kaunas',
                'postal_code' => '44280',
                'country' => 'Lietuva',
                'country_code' => 'LT',
                'confidence' => 0.96,
            ],
            [
                'short_address_line' => 'Gedimino pr. 1',
                'context_line' => '01103 Vilnius',
                'formatted_address' => 'Gedimino pr. 1, 01103 Vilnius, Lietuva',
                'latitude' => 54.6872,
                'longitude' => 25.2797,
                'place_id' => 'fake-'.$seed.'-2',
                'street_name' => 'Gedimino pr.',
                'street_number' => '1',
                'city' => 'Vilnius',
                'postal_code' => '01103',
                'country' => 'Lietuva',
                'country_code' => 'LT',
                'confidence' => 0.93,
            ],
        ];

        return collect($fake);
    }

    private function maybeFakeReverse(float $lat, float $lng): ?object
    {
        $shouldFake = app()->hasDebugModeEnabled() && (bool) config('services.nominatim.fake_on_fail', false);
        if (! $shouldFake) {
            return null;
        }

        return (object) [
            'formatted_address' => 'Fake gatvė 1, Testopolis',
            'latitude' => $lat,
            'longitude' => $lng,
            'street_name' => 'Fake gatvė',
            'street_number' => '1',
            'city' => 'Testopolis',
            'state' => null,
            'postal_code' => '00000',
            'country' => 'Lietuva',
            'country_code' => 'LT',
            'provider' => 'fake',
            'confidence_score' => 0.9,
            'address_type' => 'building',
        ];
    }
}
