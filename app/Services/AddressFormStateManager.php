<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\GeocodingServiceInterface;
use App\Enums\AddressStateEnum;
use App\Enums\AddressTypeEnum;
use App\Enums\InputModeEnum;
use App\Support\SourceLock;
use Carbon\CarbonInterface;
use App\Support\TextNormalizer;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Centralizuotas adreso formos būsenos valdytojas.
 * Atsakingas už geokodavimo paiešką, rezultatų apdorojimą, būsenų perspektyvą ir
 * duomenų paruošimą išsaugojimui tiek Filament UI, tiek backend dalyje.
 */
class AddressFormStateManager
{
    private const AUTO_SELECT_CONFIDENCE = 0.9;

    private const VERIFIED_CONFIDENCE = 0.95;

    private const LOW_CONFIDENCE_THRESHOLD = 0.6;

    private const DEFAULT_LATITUDE = 54.8985;   // Kaunas

    private const DEFAULT_LONGITUDE = 23.9036;  // Kaunas

    private AddressStateEnum $currentState = AddressStateEnum::IDLE;

    private InputModeEnum $inputMode = InputModeEnum::SEARCH;

    private AddressTypeEnum $addressType = AddressTypeEnum::UNVERIFIED;

    /**
     * @var array{latitude: float|null, longitude: float|null}
     */
    private array $coordinates = [
        'latitude' => self::DEFAULT_LATITUDE,
        'longitude' => self::DEFAULT_LONGITUDE,
    ];

    /**
     * @var array<string, mixed>
     */
    private array $manualFields = [
        'formatted_address' => null,
        'street_name' => null,
        'street_number' => null,
        'city' => null,
        'postal_code' => null,
        'country' => null,
        'country_code' => 'LT',
    ];

    /**
     * @var array<string, bool>
     */
    private array $lockedFields = [];

    /**
     * @var array<string, bool>
     */
    private array $sourceFieldLocks = [];

    private Collection $suggestions;

    private string $searchQuery = '';

    private ?array $selectedSuggestion = null;

    private ?array $autoSelectSnapshot = null;

    private bool $autoSelectAlert = false;

    private ?float $confidenceScore = null;

    private int $searchToken = 0;

    private string $lastExecutedQuery = '';

    /**
     * @var array{errors: array<int, string>, warnings: array<int, string>}
     */
    private array $messages = [
        'errors' => [],
        'warnings' => [],
    ];

    /**
     * @var array<mixed>
     */
    private array $rawApiPayload = [];

    private string $countryCode = 'lt';

    private ?CarbonInterface $snapshotAt = null;

    public function __construct(private readonly GeocodingServiceInterface $geocodingService)
    {
        $this->suggestions = collect();
    }

    private function isDefaultCoordinates(mixed $lat, mixed $lng): bool
    {
        if ($lat === null || $lng === null) {
            return false;
        }
        if (! is_numeric($lat) || ! is_numeric($lng)) {
            return false;
        }
        $e = 1e-6;

        return (abs(((float) $lat) - self::DEFAULT_LATITUDE) < $e)
            && (abs(((float) $lng) - self::DEFAULT_LONGITUDE) < $e);
    }

    private function hasMeaningfulInput(): bool
    {
        if (! empty($this->selectedSuggestion)) {
            return true;
        }

        $manual = collect($this->manualFields)
            ->except(['country_code'])
            ->filter(fn ($v) => ! empty($v));
        if ($manual->isNotEmpty()) {
            return true;
        }

        if (! $this->isDefaultCoordinates($this->coordinates['latitude'] ?? null, $this->coordinates['longitude'] ?? null)) {
            return true;
        }

        return false;
    }

    private function normalizeManualFields(): void
    {
        $fieldsToTrim = ['formatted_address', 'street_name', 'street_number', 'city', 'state', 'postal_code', 'country', 'country_code'];

        foreach ($fieldsToTrim as $field) {
            if (! array_key_exists($field, $this->manualFields)) {
                continue;
            }

            $raw = $this->manualFields[$field];

            if (is_string($raw)) {
                $raw = $this->sanitizeUtf8($raw);
            }

            if ($raw === null) {
                $this->manualFields[$field] = null;

                continue;
            }

            $trimmed = trim((string) $raw);
            $this->manualFields[$field] = $trimmed === '' ? null : $trimmed;
        }

        if (empty($this->manualFields['country'])) {
            $this->manualFields['country'] = 'Lietuva';
        }

        $this->manualFields['country_code'] = Str::upper($this->manualFields['country_code'] ?? 'LT');
    }

    private function buildAddressSignature(): ?string
    {
        $signatureParts = [
            Str::lower($this->manualFields['street_name'] ?? ''),
            Str::lower($this->manualFields['street_number'] ?? ''),
            Str::lower($this->manualFields['city'] ?? ''),
            Str::upper($this->manualFields['country_code'] ?? ''),
            (string) ($this->manualFields['postal_code'] ?? ''),
        ];

        $signatureString = implode('|', array_map(static fn ($value) => trim((string) $value), $signatureParts));

        if ($signatureString === '||||') {
            return null;
        }

        return hash('sha256', $signatureString, true);
    }

    private function buildStreetSegment(): ?string
    {
        $street = trim((string) ($this->manualFields['street_name'] ?? ''));
        $number = trim((string) ($this->manualFields['street_number'] ?? ''));

        if ($street === '' && $number === '') {
            return null;
        }

        return trim($street.' '.$number);
    }

    private function buildDisplayAddress(): ?string
    {
        $parts = array_filter([
            $this->buildStreetSegment(),
            $this->manualFields['city'] ?? null,
            $this->manualFields['state'] ?? null,
            $this->manualFields['country'] ?? null,
        ], fn ($value) => filled($value));

        if (empty($parts)) {
            return null;
        }

        return implode(', ', $parts);
    }

    /**
     * Pradinė būsena (naudojama formą hydratuojant iš DB).
     */
    public function initialize(array $currentData): self
    {
        if (empty(array_filter($currentData))) {
            return $this;
        }

        $this->coordinates['latitude'] = isset($currentData['latitude'])
            ? (float) $currentData['latitude']
            : $this->coordinates['latitude'];
        $this->coordinates['longitude'] = isset($currentData['longitude'])
            ? (float) $currentData['longitude']
            : $this->coordinates['longitude'];

        foreach ($this->manualFields as $field => $value) {
            if (array_key_exists($field, $currentData)) {
                $this->manualFields[$field] = $currentData[$field];
            }
        }

        if (isset($currentData['locked_fields']) && is_array($currentData['locked_fields'])) {
            $this->lockedFields = array_fill_keys($currentData['locked_fields'], true);
        }

        if (isset($currentData['address_type'])) {
            $this->addressType = AddressTypeEnum::tryFrom($currentData['address_type']) ?? $this->addressType;
        }

        if (isset($currentData['confidence_score'])) {
            $this->confidenceScore = is_numeric($currentData['confidence_score'])
                ? (float) $currentData['confidence_score']
                : null;
        }

        if (isset($currentData['raw_api_response']) && is_array($currentData['raw_api_response'])) {
            $this->rawApiPayload = $this->filterRawPayload($currentData['raw_api_response']);
        }

        if ($this->hasExistingAddressData()) {
            $this->currentState = AddressStateEnum::CONFIRMED;
            $this->inputMode = InputModeEnum::MIXED;
        }

        return $this;
    }

    /**
     * Nustato paieškai naudojamą šalies kodą.
     */
    public function setCountryCode(?string $countryCode): void
    {
        if (! empty($countryCode)) {
            $this->countryCode = strtolower($countryCode);
        }
    }

    /**
     * Pažymi laukus, kurie yra užrakinti dėl SourceLock ir negali būti redaguojami.
     *
     * @param  array<int, string>  $fields
     */
    public function setSourceFieldLocks(array $fields): void
    {
        $this->sourceFieldLocks = [];

        foreach ($fields as $field) {
            $key = trim((string) $field);

            if ($key === '') {
                continue;
            }

            $this->sourceFieldLocks[$key] = true;
        }
    }

    /**
     * Apdoroja gyvą vartotojo įvestį paieškos laukelyje.
     */
    public function handleSearchInput(string $query, ?string $countryCode = null): void
    {
        if ($countryCode) {
            $this->setCountryCode($countryCode);
        }

        $trimmedQuery = trim($query);
        $normalizedQuery = $this->normalizeSearchQuery($trimmedQuery);

        $this->searchQuery = $trimmedQuery;
        $this->autoSelectAlert = false;
        $this->autoSelectSnapshot = null;
        if ($this->searchQuery !== '') {
            $this->clearMessages();
        }

        $this->logDebug('AddressManager: handleSearchInput', [
            'query' => $this->searchQuery,
            'normalized' => $normalizedQuery,
            'country' => $this->countryCode,
        ]);

        if (mb_strlen($normalizedQuery) < 3) {
            $this->suggestions = collect();
            $this->currentState = $this->selectedSuggestion
                ? AddressStateEnum::CONFIRMED
                : AddressStateEnum::IDLE;

            return;
        }

        if ($normalizedQuery === $this->lastExecutedQuery && $this->suggestions->isNotEmpty()) {
            return;
        }

        $this->currentState = AddressStateEnum::SEARCHING;
        $this->inputMode = InputModeEnum::SEARCH;
        $token = ++$this->searchToken;

        try {
            $results = $this->geocodingService->search($trimmedQuery, [
                'country_codes' => $this->countryCode,
                'limit' => 5,
            ]);

            if ($token !== $this->searchToken) {
                return;
            }

            $this->lastExecutedQuery = $normalizedQuery;

            // Preserve search_query while updating suggestions.
            $this->handleSearchResults($results);
        } catch (Throwable $exception) {
            Log::error('Adresų paieška nepavyko.', [
                'query' => $normalizedQuery,
                'error' => $exception->getMessage(),
            ]);

            $this->currentState = AddressStateEnum::ERROR;
            $this->messages['errors'][] = 'Nepavyko atlikti adresų paieškos. Bandykite dar kartą vėliau.';
        }
    }

    /**
     * Apdoroja geokodavimo tarnybos paieškos rezultatus.
     */
    public function handleSearchResults(Collection $results): void
    {
        $this->applySearchStatusWarnings();

        $this->suggestions = $results
            ->take(8)
            ->map(fn ($item) => $this->prepareSuggestion($item))
            ->values();

        $this->logDebug('AddressManager: handleSearchResults', [
            'count' => $this->suggestions->count(),
        ]);

        if ($this->suggestions->isEmpty()) {
            $this->currentState = AddressStateEnum::NO_RESULTS;
        $this->pushWarning(__('address.no_results'));
            $this->clearSearchStatus();

            return;
        }

        $this->currentState = AddressStateEnum::SUGGESTIONS;

        if ($this->suggestions->count() === 1) {
            $best = $this->suggestions->first();
            $confidence = (float) ($best['confidence'] ?? 0.0);

            if ($confidence >= self::AUTO_SELECT_CONFIDENCE && ! empty($best['place_id'])) {
                $this->autoSelectSnapshot = [
                    'state' => [
                        'manual_fields' => $this->manualFields,
                        'coordinates' => $this->coordinates,
                        'locked_fields' => $this->lockedFields,
                        'address_type' => $this->addressType->value,
                        'selected_suggestion' => $this->selectedSuggestion,
                        'confidence_score' => $this->confidenceScore,
                        'raw_payload' => $this->rawApiPayload,
                    ],
                    'suggestions' => $this->suggestions->toArray(),
                    'search_query' => $this->searchQuery,
                ];

                $this->selectSuggestion((string) $best['place_id'], true);
                $this->autoSelectAlert = true;
            }
        }

        $this->clearSearchStatus();
    }

    /**
     * Pasirenkamas pasiūlymas iš sąrašo.
     */
    public function selectSuggestion(string|int $placeId, bool $autoSelected = false): void
    {
        $pid = (string) $placeId;
        $suggestion = $this->suggestions->first(function ($item) use ($pid) {
            return (string) ($item['place_id'] ?? '') === $pid;
        });

        if (! $suggestion && is_array($this->selectedSuggestion) && (string) ($this->selectedSuggestion['place_id'] ?? '') === $pid) {
            $suggestion = $this->selectedSuggestion;
        }

        if (! $suggestion && $autoSelected && $this->autoSelectSnapshot) {
            $snapshotSuggestions = collect($this->autoSelectSnapshot['suggestions'] ?? []);
            $suggestion = $snapshotSuggestions->first(function ($item) use ($pid) {
                return (string) ($item['place_id'] ?? '') === $pid;
            });
        }

        if (! $suggestion) {
            \Log::debug('AddressManager: selectSuggestion skipped (not found)', ['place_id' => $pid, 'suggestion_count' => $this->suggestions->count()]);

            return;
        }

        \Log::debug('AddressManager: selectSuggestion', ['place_id' => $pid, 'has_suggestion' => true]);

        $this->selectedSuggestion = $suggestion;
        $this->lockedFields = [];
        $this->confidenceScore = isset($suggestion['confidence'])
            ? (float) $suggestion['confidence']
            : null;

        foreach (array_keys($this->manualFields) as $field) {
            if (array_key_exists($field, $suggestion)) {
                $this->manualFields[$field] = $suggestion[$field];
            }
        }

        $this->coordinates['latitude'] = isset($suggestion['latitude'])
            ? (float) $suggestion['latitude']
            : $this->coordinates['latitude'];
        $this->coordinates['longitude'] = isset($suggestion['longitude'])
            ? (float) $suggestion['longitude']
            : $this->coordinates['longitude'];

        $this->addressType = match (true) {
            ($this->confidenceScore ?? 0) >= self::VERIFIED_CONFIDENCE => AddressTypeEnum::VERIFIED,
            ($this->confidenceScore ?? 0) >= self::LOW_CONFIDENCE_THRESHOLD => AddressTypeEnum::LOW_CONFIDENCE,
            default => AddressTypeEnum::UNVERIFIED,
        };

        $rawPayload = Arr::get($suggestion, 'raw')
            ?? Arr::get($suggestion, 'raw_payload')
            ?? Arr::except($suggestion, ['raw', 'raw_payload']);
        $this->rawApiPayload = is_array($rawPayload) ? $this->filterRawPayload($rawPayload) : [];

        $this->suggestions = collect();
        $this->searchQuery = '';
        $this->currentState = AddressStateEnum::SUGGESTIONS;
        $this->inputMode = InputModeEnum::SEARCH;

        if (! $autoSelected) {
            $this->autoSelectSnapshot = null;
            $this->autoSelectAlert = false;
        }
    }

    /**
     * Vartotojas grįžta iš auto-select būsenos norėdamas pasirinkti kitą adresą.
     */
    public function undoAutoSelect(): void
    {
        if (! $this->autoSelectAlert || ! $this->autoSelectSnapshot) {
            return;
        }

        $snapshot = $this->autoSelectSnapshot['state'] ?? [];

        $this->manualFields = $snapshot['manual_fields'] ?? $this->manualFields;
        $this->coordinates = $snapshot['coordinates'] ?? $this->coordinates;
        $this->lockedFields = $snapshot['locked_fields'] ?? $this->lockedFields;
        $this->addressType = AddressTypeEnum::tryFrom($snapshot['address_type'] ?? '') ?? AddressTypeEnum::UNVERIFIED;
        $this->selectedSuggestion = $snapshot['selected_suggestion'] ?? null;
        $this->confidenceScore = $snapshot['confidence_score'] ?? null;
        $this->rawApiPayload = $snapshot['raw_payload'] ?? [];

        $this->suggestions = collect($this->autoSelectSnapshot['suggestions'] ?? []);
        $this->searchQuery = $this->autoSelectSnapshot['search_query'] ?? $this->searchQuery;

        $this->currentState = AddressStateEnum::SUGGESTIONS;
        $this->inputMode = InputModeEnum::SEARCH;
        $this->autoSelectAlert = false;
        $this->autoSelectSnapshot = null;
    }

    /**
     * Perjungia į rankinio įvedimo režimą.
     */
    public function switchToManualMode(): void
    {
        $this->inputMode = InputModeEnum::MANUAL;
        $this->currentState = AddressStateEnum::MANUAL;
        $this->addressType = AddressTypeEnum::UNVERIFIED;
        $this->autoSelectAlert = false;
    }

    public function forceAddressType(AddressTypeEnum $type, ?float $confidence = null): void
    {
        $this->addressType = $type;

        if ($confidence !== null) {
            $this->confidenceScore = $confidence;
        }
    }

    public function beginEditing(): void
    {
        if ($this->currentState === AddressStateEnum::CONFIRMED) {
            $this->currentState = AddressStateEnum::MANUAL;
        }
    }

    public function markConfirmed(?InputModeEnum $mode = null): void
    {
        $this->currentState = AddressStateEnum::CONFIRMED;
        $this->inputMode = $mode ?? $this->inputMode ?? InputModeEnum::MIXED;
        $this->snapshotAt = Carbon::now();
    }

    /**
     * Overwrite manual fields without marking them locked (used for pin fallback).
     *
     * @param  array<string, mixed>  $fields
     */
    public function overwriteManualFields(array $fields): void
    {
        foreach ($fields as $key => $value) {
            if (! array_key_exists($key, $this->manualFields)) {
                continue;
            }

            if (is_string($value)) {
                $value = $this->sanitizeUtf8($value);
            }

            $this->manualFields[$key] = $value;
        }
    }

    /**
     * Rankinis laukų atnaujinimas pažymi lauką kaip „užrakintą“ nuo automatinio reverse geocode.
     */
    public function updateManualField(string $key, mixed $value): void
    {
        if (! array_key_exists($key, $this->manualFields)) {
            return;
        }

        if (! SourceLock::canWrite($key, $this->getSourceFieldLocks())) {
            throw new InvalidArgumentException(sprintf(
                '„%s“ laukas yra užrakintas ir negali būti keičiamas be override.',
                str_replace('_', ' ', $key)
            ));
        }

        if (is_string($value)) {
            $value = $this->sanitizeUtf8($value);
        }

        $this->manualFields[$key] = $value;
        $this->lockedFields[$key] = true;
        $this->currentState = AddressStateEnum::MANUAL;
        $this->inputMode = InputModeEnum::MIXED;
        $this->addressType = AddressTypeEnum::UNVERIFIED;
        $this->autoSelectAlert = false;
    }

    /**
     * Po žymeklio vilkimo atnaujinamos koordinatės, o pasirinktinai atliekamas reverse geocode.
     */
    public function updateCoordinates(float $latitude, float $longitude, bool $performReverseGeocode = false): void
    {
        $this->coordinates['latitude'] = $latitude;
        $this->coordinates['longitude'] = $longitude;
        $this->currentState = $this->currentState === AddressStateEnum::CONFIRMED
            ? AddressStateEnum::CONFIRMED
            : AddressStateEnum::MANUAL;
        $this->addressType = AddressTypeEnum::UNVERIFIED;
        $this->autoSelectAlert = false;

        if ($performReverseGeocode) {
            $this->performReverseGeocoding();
        }
    }

    /**
     * Reverse geocode (naudojamas drag-end atveju ar pradinės būsenos).
     */
    public function performReverseGeocoding(): void
    {
        $this->currentState = AddressStateEnum::SEARCHING;

        try {
            $resultObject = $this->geocodingService->reverse(
                $this->coordinates['latitude'],
                $this->coordinates['longitude']
            );

            if (! $resultObject) {
                $this->messages['warnings'][] = 'Nepavyko rasti adreso pagal šias koordinates.';
                $this->addressType = AddressTypeEnum::VIRTUAL;
                $this->currentState = AddressStateEnum::MANUAL;

                return;
            }

            $this->handleReverseGeocodeResult($this->normaliseResult($resultObject));
        } catch (Throwable $exception) {
            Log::error('Reverse geokodavimas nepavyko.', [
                'latitude' => $this->coordinates['latitude'],
                'longitude' => $this->coordinates['longitude'],
                'error' => $exception->getMessage(),
            ]);

            $this->currentState = AddressStateEnum::ERROR;
            $this->messages['errors'][] = 'Nepavyko gauti adreso pagal koordinates.';
        }
    }

    /**
     * Apdoroja atvirkštinio geokodavimo rezultatą, neperrašydamas rankiniu būdu užrakintų laukų.
     */
    public function handleReverseGeocodeResult(array $result): void
    {
        foreach (array_keys($this->manualFields) as $field) {
            if ($this->isLocked($field)) {
                continue;
            }

            if (array_key_exists($field, $result)) {
                $this->manualFields[$field] = $result[$field];
            }
        }

        $this->addressType = AddressTypeEnum::UNVERIFIED;
        $confidence = isset($result['confidence_score']) ? (float) $result['confidence_score'] : null;
        $this->confidenceScore = $confidence;

        if ($confidence !== null) {
            $this->addressType = match (true) {
                $confidence >= self::VERIFIED_CONFIDENCE => AddressTypeEnum::VERIFIED,
                $confidence >= self::LOW_CONFIDENCE_THRESHOLD => AddressTypeEnum::LOW_CONFIDENCE,
                default => AddressTypeEnum::UNVERIFIED,
            };
        }

        $this->rawApiPayload = $this->filterRawPayload($result);
        $this->currentState = AddressStateEnum::MANUAL;
        $this->inputMode = InputModeEnum::MANUAL;
    }

    /**
     * Sugeneruoja būsenos momentinę nuotrauką Filament komponentui.
     */
    public function getStateSnapshot(): array
    {
        return [
            'current_state' => $this->currentState->value,
            'input_mode' => $this->inputMode->value,
            'address_type' => $this->addressType->value,
            'snapshot_at' => $this->snapshotAt?->toIso8601String(),
            'search_query' => $this->searchQuery,
            'suggestions' => $this->suggestions->values()->all(),
            'selected_suggestion' => $this->selectedSuggestion,
            'selected_place_id' => $this->selectedSuggestion['place_id'] ?? null,
            'manual_fields' => $this->manualFields,
            'coordinates' => $this->coordinates,
            'locked_fields' => array_keys(array_filter($this->lockedFields)),
            'source_field_locks' => array_keys(array_filter($this->sourceFieldLocks)),
            'auto_select_alert' => $this->autoSelectAlert,
            'messages' => $this->messages,
            'confidence_score' => $this->confidenceScore,
            'raw_api_payload' => $this->rawApiPayload,
        ];
    }

    /**
     * Atstato būseną iš išsaugotos momentinės nuotraukos.
     */
    public function restoreState(array $state): void
    {
        Log::debug('[MANAGER] restoreState INPUT', [
            'input_manual_fields' => $state['manual_fields'] ?? null,
            'input_address_type' => $state['address_type'] ?? null,
            'input_current_state' => $state['current_state'] ?? null,
        ]);

        if (config('app.debug')) {
            Log::debug('AddressFormStateManager restoring state', [
                'address_type' => $state['address_type'] ?? null,
                'coordinates' => $state['coordinates'] ?? null,
            ]);
        }

        $restoredAddressType = AddressTypeEnum::tryFrom($state['address_type'] ?? '') ?? $this->addressType;
        $restoredState = AddressStateEnum::tryFrom($state['current_state'] ?? '') ?? null;
        if ($restoredState === null && $restoredAddressType !== AddressTypeEnum::UNVERIFIED) {
            $restoredState = AddressStateEnum::CONFIRMED;
        }

        $this->currentState = $restoredState ?? $this->currentState;
        $this->inputMode = InputModeEnum::tryFrom($state['input_mode'] ?? '') ?? $this->inputMode;
        $this->addressType = $restoredAddressType;
        $snapshotAt = $state['snapshot_at'] ?? null;
        $this->snapshotAt = $snapshotAt ? Carbon::make($snapshotAt) : $this->snapshotAt;
        $this->searchQuery = $state['search_query'] ?? $this->searchQuery;
        $this->suggestions = collect($state['suggestions'] ?? []);
        $this->selectedSuggestion = $state['selected_suggestion'] ?? $this->selectedSuggestion;
        if (($state['selected_place_id'] ?? null) && $this->selectedSuggestion) {
            $this->selectedSuggestion['place_id'] = $state['selected_place_id'];
        }

        // Bridge nested `live` state written by embedded search component, if present
        $live = $state['live'] ?? null;
        if (is_array($live)) {
            if (! empty($live['suggestions']) && is_array($live['suggestions'])) {
                $this->suggestions = collect($live['suggestions']);
            }

            if (! empty($live['selected_suggestion']) && is_array($live['selected_suggestion'])) {
                $this->selectedSuggestion = $live['selected_suggestion'];
            }

            if (! empty($live['selected_place_id']) && is_string($live['selected_place_id'])) {
                if (is_array($this->selectedSuggestion)) {
                    $this->selectedSuggestion['place_id'] = (string) $live['selected_place_id'];
                }
            }
        }

        $this->manualFields = array_merge($this->manualFields, $state['manual_fields'] ?? []);
        $this->coordinates = array_merge($this->coordinates, $state['coordinates'] ?? []);
        $locked = $state['locked_fields'] ?? [];
        $this->lockedFields = is_array($locked) ? array_fill_keys($locked, true) : $this->lockedFields;
        $sourceLocked = $state['source_field_locks'] ?? [];
        $this->sourceFieldLocks = is_array($sourceLocked) ? array_fill_keys($sourceLocked, true) : $this->sourceFieldLocks;
        $this->autoSelectAlert = (bool) ($state['auto_select_alert'] ?? false);
        $this->messages = array_merge($this->messages, Arr::only($state['messages'] ?? [], ['errors', 'warnings']));
        $this->confidenceScore = $state['confidence_score'] ?? $this->confidenceScore;
        $this->rawApiPayload = is_array($state['raw_api_payload'] ?? null)
            ? $state['raw_api_payload']
            : $this->rawApiPayload;

        Log::debug('[MANAGER] restoreState RESULT', [
            'stored_manual_fields' => $this->manualFields,
            'stored_address_type' => $this->addressType->value,
            'stored_current_state' => $this->currentState->value,
        ]);
    }

    /**
     * Patikrina ir paruošia duomenis išsaugojimui.
     *
     * @return array{data: array<string, mixed>, errors: array<int, string>, warnings: array<int, string>}
     */
    public function validateAndPrepareForSubmission(): array
    {
        $errors = [];
        $warnings = [];
        $addressConfirmed = $this->currentState === AddressStateEnum::CONFIRMED;

        if (app()->environment(['local', 'testing'])) {
            Log::info('addr:manager:validate:start', [
                'state' => $this->currentState->value,
                'input_mode' => $this->inputMode->value,
                'manual' => Arr::only($this->manualFields, [
                    'street_name',
                    'street_number',
                    'city',
                    'postal_code',
                ]),
            ]);
        }

        if (! $addressConfirmed) {
            if ($this->currentState === AddressStateEnum::IDLE && ! $this->hasMeaningfulInput()) {
                $errors[] = 'Prašome įvesti ir pasirinkti adresą.';
            }

            if ($this->currentState === AddressStateEnum::SUGGESTIONS) {
                $errors[] = 'Pasirinkite adresą iš pasiūlymų arba pereikite į rankinį režimą.';
            }
        }

        // Prefer coordinates from selected suggestion first, then raw payload
        if (is_array($this->selectedSuggestion ?? null)) {
            $sLat = $this->selectedSuggestion['latitude'] ?? null;
            $sLng = $this->selectedSuggestion['longitude'] ?? null;
            if (is_numeric($sLat) && is_numeric($sLng)) {
                $this->coordinates['latitude'] = (float) $sLat;
                $this->coordinates['longitude'] = (float) $sLng;
            }
        }

        if (is_array($this->rawApiPayload)) {
            $rLat = $this->rawApiPayload['latitude'] ?? null;
            $rLng = $this->rawApiPayload['longitude'] ?? null;
            if (is_numeric($rLat) && is_numeric($rLng)) {
                $this->coordinates['latitude'] = (float) $rLat;
                $this->coordinates['longitude'] = (float) $rLng;
            }
        }

        // Prefer precise coordinates from raw payload if available
        // (kept above with corrected precedence)

        // Ensure coordinates use floats
        $lat = $this->coordinates['latitude'] ?? null;
        $lng = $this->coordinates['longitude'] ?? null;
        if (! is_numeric($lat) || ! is_numeric($lng)) {
            $errors[] = 'Nenurodytos koordinatės.';
        }

        $normalised = null;

        if (is_array($this->selectedSuggestion ?? null)) {
            $normalised = $this->normaliseResult($this->selectedSuggestion);
        } elseif (is_array($this->rawApiPayload ?? null)) {
            $normalised = $this->normaliseResult($this->rawApiPayload);
        }

        if ($normalised) {
            foreach (['formatted_address', 'street_name', 'street_number', 'city', 'postal_code', 'country', 'country_code'] as $field) {
                if (empty($this->manualFields[$field]) && isset($normalised[$field])) {
                    $this->manualFields[$field] = $normalised[$field];
                }
            }

            if (empty($this->lockedFields['street_name'] ?? false) && ! empty($normalised['street_name'] ?? null)) {
                $this->manualFields['street_name'] = $normalised['street_name'];
            }
            if (empty($this->lockedFields['city'] ?? false) && ! empty($normalised['city'] ?? null)) {
                $this->manualFields['city'] = $normalised['city'];
            }

            if (is_numeric($normalised['latitude'] ?? null) && is_numeric($normalised['longitude'] ?? null)) {
                $this->coordinates['latitude'] = (float) $normalised['latitude'];
                $this->coordinates['longitude'] = (float) $normalised['longitude'];
            }

            if (! is_numeric($this->confidenceScore ?? null) && isset($normalised['confidence'])) {
                $this->confidenceScore = (float) $normalised['confidence'];
            }

            $this->rawApiPayload = $this->filterRawPayload($normalised);
        }

        $this->normalizeManualFields();

        if (! is_numeric($this->coordinates['latitude']) || ! is_numeric($this->coordinates['longitude'])) {
            $errors[] = 'Nenurodytos koordinatės.';
        }

        $hasCity = filled($this->manualFields['city'] ?? null);
        $hasStreet = filled($this->manualFields['street_name'] ?? null);
        $hasStreetNumber = filled($this->manualFields['street_number'] ?? null);

        if ($hasCity && $hasStreet && ! $hasStreetNumber && ! is_numeric($this->confidenceScore ?? null)) {
            $this->confidenceScore = 0.65;
            $this->addressType = AddressTypeEnum::LOW_CONFIDENCE;
        }

        if (is_numeric($this->confidenceScore ?? null)) {
            $this->confidenceScore = (float) $this->confidenceScore;
            $this->addressType = match (true) {
                $this->confidenceScore >= self::VERIFIED_CONFIDENCE => AddressTypeEnum::VERIFIED,
                $this->confidenceScore >= self::LOW_CONFIDENCE_THRESHOLD => AddressTypeEnum::LOW_CONFIDENCE,
                default => AddressTypeEnum::UNVERIFIED,
            };
        }

        if (empty($this->manualFields['city'])) {
            if ($addressConfirmed) {
                $warnings[] = 'Geokodavimo atsakymas negrąžino miesto – patikrinkite prieš išsaugant.';
            } else {
                $errors[] = 'Miestas yra privalomas laukas.';
            }
        }

        if (empty($this->manualFields['street_name']) && empty($this->manualFields['formatted_address'])) {
            $warnings[] = 'Nenurodytas gatvės pavadinimas.';
        }

        if (in_array($this->addressType, [AddressTypeEnum::UNVERIFIED, AddressTypeEnum::LOW_CONFIDENCE], true) && empty($this->manualFields['formatted_address'])) {
            $warnings[] = 'Adresas nėra pilnai patvirtintas geokodavimo tarnybos.';
        }

        if (! empty($this->lockedFields)) {
            $warnings[] = 'Kai kurie laukų duomenys buvo užrakinti rankiniu būdu ir gali neatitikti geokodavimo rezultatų.';
        }

        $this->messages = [
            'errors' => $errors,
            'warnings' => $warnings,
        ];

        $displayAddress = $this->buildDisplayAddress();
        if ($displayAddress) {
            $this->manualFields['formatted_address'] = $displayAddress;
        }

        $providerRaw = $this->rawApiPayload['provider'] ?? 'nominatim';
        $provider = Str::lower(trim((string) $providerRaw));
        $providerPlaceIdRaw = $this->rawApiPayload['place_id'] ?? ($this->selectedSuggestion['place_id'] ?? null);
        $providerPlaceId = filled($providerPlaceIdRaw) ? (string) $providerPlaceIdRaw : null;
        $osmTypeRaw = $this->rawApiPayload['osm_type'] ?? null;
        $osmType = filled($osmTypeRaw) ? Str::lower(trim((string) $osmTypeRaw)) : null;
        $osmId = $this->rawApiPayload['osm_id'] ?? null;
        $osmId = is_numeric($osmId) ? (int) $osmId : null;
        $addressSignature = $this->buildAddressSignature();

        $data = array_merge($this->manualFields, [
            'latitude' => round((float) $this->coordinates['latitude'], 6),
            'longitude' => round((float) $this->coordinates['longitude'], 6),
            'address_type' => $this->addressType->value,
            'confidence_score' => $this->confidenceScore,
            'raw_api_response' => $this->rawApiPayload,
            'locked_fields' => array_keys(array_filter($this->lockedFields)),
            'provider' => $provider,
            'provider_place_id' => $providerPlaceId,
            'osm_type' => $osmType,
            'osm_id' => $osmId,
            'address_signature' => $addressSignature,
            'snapshot_at' => $this->snapshotAt?->toIso8601String(),
        ]);

        if (app()->environment(['local', 'testing'])) {
            Log::info('addr:manager:validate:end', [
                'errors' => $errors,
                'warnings' => $warnings,
                'address_type' => $this->addressType->value,
                'confidence' => $this->confidenceScore,
            ]);

            Log::debug('addr:manager:validate:snapshot', [
                'addressType' => $this->addressType->value,
                'manual_fields' => $this->manualFields,
                'coordinates' => $this->coordinates,
            ]);

            Log::info('AddressFormStateManager prepared data', [
                'data' => $data,
            ]);
        }

        return compact('data', 'errors', 'warnings');
    }

    /**
     * Grąžina duomenis išsaugojimui. Jei yra hard klaidų – išmetama išimtis.
     */
    public function getDataForModel(): array
    {
        $result = $this->validateAndPrepareForSubmission();

        if (! empty($result['errors'])) {
            throw new RuntimeException('Adreso duomenys negali būti išsaugoti: '.implode(' ', $result['errors']));
        }

        return $result['data'];
    }

    public function getCurrentState(): AddressStateEnum
    {
        return $this->currentState;
    }

    public function getInputMode(): InputModeEnum
    {
        return $this->inputMode;
    }

    public function getAddressType(): AddressTypeEnum
    {
        return $this->addressType;
    }

    public function getCoordinates(): array
    {
        return $this->coordinates;
    }

    public function getManualFields(): array
    {
        return $this->manualFields;
    }

    public function getSuggestions(): Collection
    {
        return $this->suggestions;
    }

    public function getSearchQuery(): string
    {
        return $this->searchQuery;
    }

    public function getSelectedSuggestion(): ?array
    {
        return $this->selectedSuggestion;
    }

    public function getLockedFields(): array
    {
        return array_keys(array_filter($this->lockedFields));
    }

    public function getSourceFieldLocks(): array
    {
        return array_keys(array_filter($this->sourceFieldLocks));
    }

    public function isAutoSelectAlertVisible(): bool
    {
        return $this->autoSelectAlert;
    }

    public function getMessages(): array
    {
        return $this->messages;
    }

    public function pushMessage(string $type, string $message): void
    {
        if (! array_key_exists($type, $this->messages)) {
            $this->messages[$type] = [];
        }

        $this->messages[$type][] = $message;
    }

    public function getRawApiPayload(): array
    {
        return $this->rawApiPayload;
    }

    /**
     * Normalizuoja geokodavimo tarnybos atsakymą į paprastą masyvą.
     */
    private function normaliseResult(object|array $result): array
    {
        if (is_array($result)) {
            return $result;
        }

        if (method_exists($result, 'toArray')) {
            return $result->toArray();
        }

        return collect($result)->toArray();
    }

    private function hasExistingAddressData(): bool
    {
        return collect($this->manualFields)
            ->except(['country_code'])
            ->filter(fn ($value) => ! empty($value))
            ->isNotEmpty();
    }

    private function isLocked(string $field): bool
    {
        return ($this->lockedFields[$field] ?? false) === true;
    }

    private function isSourceLockedField(string $field): bool
    {
        return ($this->sourceFieldLocks[$field] ?? false) === true;
    }

    private function clearMessages(): void
    {
        $this->messages = [
            'errors' => [],
            'warnings' => [],
        ];
    }

    private function applySearchStatusWarnings(): void
    {
        $status = null;
        if (method_exists($this->geocodingService, 'getLastStatus')) {
            $status = $this->geocodingService->getLastStatus('search');
        }

        if ($status === 'rate_limited') {
            $this->pushWarning(__('address.rate_limited'));
        } elseif ($status === 'breaker_open') {
            $this->pushWarning(__('address.provider_offline'));
        }
    }

    private function pushWarning(string $message): void
    {
        if (! in_array($message, $this->messages['warnings'], true)) {
            $this->messages['warnings'][] = $message;
        }
    }

    private function clearSearchStatus(): void
    {
        if (method_exists($this->geocodingService, 'clearStatus')) {
            $this->geocodingService->clearStatus('search');
        }
    }

    /**
     * Pašalina potencialiai jautrius ar mums nereikalingus laukus.
     */
    private function filterRawPayload(array $payload): array
    {
        $sensitiveKeys = [
            'licence',
            'attribution',
            'query',
            'timestamp',
            'confidence',
            'confidence_score',
            'boundingbox',
            'importance',
        ];

        foreach ($sensitiveKeys as $key) {
            unset($payload[$key]);
        }

        return $payload;
    }

    private function normalizeSearchQuery(string $query): string
    {
        $normalized = preg_replace('/\s+/', ' ', Str::lower($query));

        return $normalized === null ? '' : $normalized;
    }

    private function prepareSuggestion(array $item): array
    {
        $rawItem = $item;
        $payload = $this->filterRawPayload($rawItem);

        $latitude = $rawItem['latitude'] ?? $rawItem['lat'] ?? ($payload['latitude'] ?? $payload['lat'] ?? null);
        $longitude = $rawItem['longitude'] ?? $rawItem['lon'] ?? ($payload['longitude'] ?? $payload['lon'] ?? null);
        $street = $rawItem['street_name']
            ?? $payload['street_name']
            ?? $rawItem['street']
            ?? $payload['street']
            ?? null;
        $houseNumber = $rawItem['street_number']
            ?? $payload['street_number']
            ?? $rawItem['house_number']
            ?? $payload['house_number']
            ?? null;
        $postalCode = $rawItem['postal_code']
            ?? $payload['postal_code']
            ?? $rawItem['postcode']
            ?? $payload['postcode']
            ?? null;
        $state = $rawItem['state']
            ?? $payload['state']
            ?? $rawItem['region']
            ?? $payload['region']
            ?? null;
        $countryCodeRaw = $rawItem['country_code'] ?? $payload['country_code'] ?? null;
        $countryCode = $countryCodeRaw ? Str::upper((string) $countryCodeRaw) : null;

        return [
            'place_id' => (string) ($rawItem['place_id'] ?? $payload['place_id'] ?? ''),
            'short_address_line' => $rawItem['short_address_line'] ?? $payload['short_address_line'] ?? ($rawItem['display_name'] ?? $payload['display_name'] ?? ''),
            'context_line' => $rawItem['context_line'] ?? $payload['context_line'] ?? ($rawItem['city'] ?? $payload['city'] ?? ''),
            'formatted_address' => $rawItem['formatted_address'] ?? $payload['formatted_address'] ?? ($rawItem['display_name'] ?? $payload['display_name'] ?? ''),
            'latitude' => is_numeric($latitude) ? (float) $latitude : null,
            'longitude' => is_numeric($longitude) ? (float) $longitude : null,
            'street_name' => $street,
            'street_number' => $houseNumber,
            'city' => $rawItem['city'] ?? $payload['city'] ?? null,
            'state' => $state,
            'postal_code' => $postalCode,
            'country' => $rawItem['country'] ?? $payload['country'] ?? null,
            'country_code' => $countryCode,
            'confidence' => isset($rawItem['confidence']) ? (float) $rawItem['confidence'] : (isset($payload['confidence']) ? (float) $payload['confidence'] : null),
            'provider' => Str::lower((string) ($rawItem['provider'] ?? $payload['provider'] ?? 'nominatim')),
            'osm_type' => $rawItem['osm_type'] ?? $payload['osm_type'] ?? null,
            'osm_id' => isset($rawItem['osm_id']) ? (int) $rawItem['osm_id'] : (isset($payload['osm_id']) ? (int) $payload['osm_id'] : null),
            'raw_payload' => $payload,
        ];
    }

    private function logDebug(string $message, array $context = []): void
    {
        if (config('app.debug')) {
            Log::debug($message, $context);
        }
    }

    private function sanitizeUtf8(?string $value): ?string
    {
        return TextNormalizer::toNfc($value);
    }
}
