<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\GeocodingServiceInterface;
use App\Enums\AddressStateEnum;
use App\Enums\AddressTypeEnum;
use App\Enums\InputModeEnum;
use App\Support\SourceLock;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Address form state reducer that mirrors the simplified UX:
 * - user edits a pending snapshot (via search or pin),
 * - explicit confirmation finalises the snapshot (lat+lng+snapshot_at),
 * - all textual metadata is best-effort and optional.
 */
class AddressFormStateManager
{
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
        'state' => null,
        'postal_code' => null,
        'country' => null,
        'country_code' => 'LT',
    ];

    private Collection $suggestions;

    private string $searchQuery = '';

    private ?array $selectedSuggestion = null;

    private ?float $confidenceScore = null;

    /**
     * Raw payload returned by the geocoding provider (best-effort).
     *
     * @var array<mixed>
     */
    private array $rawApiPayload = [];

    /**
     * Validation/info messages bubbled up to the UI.
     *
     * @var array{errors: array<int, string>, warnings: array<int, string>}
     */
    private array $messages = [
        'errors' => [],
        'warnings' => [],
    ];

    private string $countryCode = 'lt';

    private ?CarbonInterface $snapshotAt = null;

    /**
     * Manual lock management is still exposed for legacy/manual-entry tests.
     *
     * @var array<string, bool>
     */
    private array $lockedFields = [];

    /**
     * @var array<string, bool>
     */
    private array $sourceFieldLocks = [];

    public function __construct(private readonly GeocodingServiceInterface $geocodingService)
    {
        $this->suggestions = collect();
    }

    public function setCountryCode(?string $countryCode): void
    {
        if (blank($countryCode)) {
            return;
        }

        $this->countryCode = Str::lower(Str::substr($countryCode, 0, 2));
    }

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

    public function getSourceFieldLocks(): array
    {
        return array_keys(array_filter($this->sourceFieldLocks));
    }

    public function handleSearchResults(Collection $results): void
    {
        $this->suggestions = $results
            ->filter(fn ($item) => is_array($item) && ! empty($item['place_id']))
            ->values();
        $this->selectedSuggestion = null;
        $this->snapshotAt = null;

        if ($this->suggestions->isEmpty()) {
            $this->currentState = AddressStateEnum::NO_RESULTS;
        } else {
            $this->currentState = AddressStateEnum::SUGGESTIONS;
        }

        $this->applySearchStatusWarnings();
    }

    public function selectSuggestion(string|int $placeId, bool $autoSelected = false): void // legacy signature, $autoSelected ignored
    {
        $pid = (string) $placeId;
        $suggestion = $this->suggestions->first(function ($item) use ($pid) {
            return (string) ($item['place_id'] ?? '') === $pid;
        });

        if (! $suggestion && $this->selectedSuggestion && (string) ($this->selectedSuggestion['place_id'] ?? '') === $pid) {
            $suggestion = $this->selectedSuggestion;
        }

        if (! $suggestion) {
            Log::debug('AddressFormStateManager: selectSuggestion skipped (not found)', ['place_id' => $pid]);

            return;
        }

        $this->selectedSuggestion = $suggestion;
        $this->applyGeocodePayload($suggestion);
        $this->inputMode = InputModeEnum::SEARCH;
        $this->currentState = AddressStateEnum::MANUAL;
        $this->snapshotAt = null;
    }

    public function updateCoordinates(float $latitude, float $longitude, bool $performReverseGeocode = false): void
    {
        $this->coordinates = [
            'latitude' => round($latitude, 6),
            'longitude' => round($longitude, 6),
        ];

        $this->inputMode = InputModeEnum::MANUAL;
        $this->currentState = AddressStateEnum::MANUAL;
        $this->snapshotAt = null;

        if ($performReverseGeocode) {
            $this->performReverseGeocoding();
        }
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
        $this->finalizeConfirmation($mode ?? $this->inputMode);
    }

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

        $this->normalizeManualFields();
    }

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
        $this->inputMode = InputModeEnum::MANUAL;
        $this->snapshotAt = null;
    }

    public function getStateSnapshot(): array
    {
        return [
            'current_state' => $this->currentState->value,
            'input_mode' => $this->inputMode->value,
            'address_type' => $this->addressType->value,
            'search_query' => $this->searchQuery,
            'suggestions' => $this->suggestions->values()->all(),
            'selected_suggestion' => $this->selectedSuggestion,
            'selected_place_id' => $this->selectedSuggestion['place_id'] ?? null,
            'manual_fields' => $this->manualFields,
            'coordinates' => $this->coordinates,
            'locked_fields' => array_keys(array_filter($this->lockedFields)),
            'source_field_locks' => $this->getSourceFieldLocks(),
            'auto_select_alert' => false,
            'messages' => $this->messages,
            'confidence_score' => $this->confidenceScore,
            'raw_api_payload' => $this->rawApiPayload,
            'snapshot_at' => $this->snapshotAt?->toIso8601String(),
        ];
    }

    public function restoreState(array $state): void
    {
        $this->currentState = AddressStateEnum::tryFrom($state['current_state'] ?? '') ?? $this->currentState;
        $this->inputMode = InputModeEnum::tryFrom($state['input_mode'] ?? '') ?? $this->inputMode;
        $this->addressType = AddressTypeEnum::tryFrom($state['address_type'] ?? '') ?? $this->addressType;
        $this->searchQuery = (string) ($state['search_query'] ?? $this->searchQuery);

        if (! empty($state['suggestions']) && is_array($state['suggestions'])) {
            $this->suggestions = collect($state['suggestions']);
        }

        $this->selectedSuggestion = $state['selected_suggestion'] ?? $this->selectedSuggestion;
        $this->manualFields = array_replace($this->manualFields, $state['manual_fields'] ?? []);
        $this->coordinates = array_replace($this->coordinates, $state['coordinates'] ?? []);

        $this->lockedFields = [];
        foreach (($state['locked_fields'] ?? []) as $field) {
            $this->lockedFields[$field] = true;
        }

        $this->setSourceFieldLocks($state['source_field_locks'] ?? []);

        $this->messages = array_replace($this->messages, Arr::only($state['messages'] ?? [], ['errors', 'warnings']));
        $this->confidenceScore = isset($state['confidence_score']) ? (float) $state['confidence_score'] : $this->confidenceScore;
        $this->rawApiPayload = is_array($state['raw_api_payload'] ?? null) ? $state['raw_api_payload'] : $this->rawApiPayload;
        $this->snapshotAt = isset($state['snapshot_at']) ? Carbon::make($state['snapshot_at']) : $this->snapshotAt;
    }

    public function validateAndPrepareForSubmission(): array
    {
        $errors = [];
        $warnings = [];

        if ($this->snapshotAt === null || $this->currentState !== AddressStateEnum::CONFIRMED) {
            $errors[] = 'Patvirtinkite lokaciją žemėlapyje.';
        }

        if (! is_numeric($this->coordinates['latitude']) || ! is_numeric($this->coordinates['longitude'])) {
            $errors[] = 'Koordinatės negalioja.';
        }

        if ($errors !== []) {
            return compact('errors', 'warnings') + ['data' => []];
        }

        $this->normalizeManualFields();

        if (empty($this->manualFields['formatted_address'])) {
            $this->manualFields['formatted_address'] = $this->buildDisplayAddress();
        }

        $data = array_merge($this->manualFields, [
            'latitude' => round((float) $this->coordinates['latitude'], 6),
            'longitude' => round((float) $this->coordinates['longitude'], 6),
            'address_type' => $this->addressType->value,
            'confidence_score' => $this->confidenceScore,
            'raw_api_response' => $this->rawApiPayload,
            'provider' => $this->rawApiPayload['provider'] ?? 'nominatim',
            'provider_place_id' => $this->rawApiPayload['place_id'] ?? ($this->selectedSuggestion['place_id'] ?? null),
            'address_signature' => $this->buildAddressSignature(),
            'snapshot_at' => $this->snapshotAt?->toIso8601String(),
        ]);

        return compact('data', 'errors', 'warnings');
    }

    public function getDataForModel(): array
    {
        $result = $this->validateAndPrepareForSubmission();

        if ($result['errors'] !== []) {
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

    private function performReverseGeocoding(): void
    {
        $lat = $this->coordinates['latitude'];
        $lng = $this->coordinates['longitude'];

        if (! is_numeric($lat) || ! is_numeric($lng)) {
            $this->pushMessage('errors', 'Koordinatės negalioja.');

            return;
        }

        try {
            $result = $this->geocodingService->reverse((float) $lat, (float) $lng);
            if (! $result) {
                $this->pushMessage('warnings', 'Nepavyko rasti adreso pagal koordinates.');

                return;
            }

            $this->applyGeocodePayload($result->toArray());
        } catch (Throwable $exception) {
            Log::warning('AddressFormStateManager: reverse geocode failed', [
                'latitude' => $lat,
                'longitude' => $lng,
                'message' => $exception->getMessage(),
            ]);

            $this->pushMessage('warnings', 'Nepavyko atlikti reverse geokodavimo.');
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function applyGeocodePayload(array $payload): void
    {
        $this->manualFields['formatted_address'] = $payload['formatted_address'] ?? ($payload['display_name'] ?? $this->manualFields['formatted_address']);
        $this->manualFields['street_name'] = $payload['street_name'] ?? $payload['street'] ?? $this->manualFields['street_name'];
        $this->manualFields['street_number'] = $payload['street_number'] ?? $payload['house_number'] ?? $this->manualFields['street_number'];
        $this->manualFields['city'] = $payload['city'] ?? $payload['town'] ?? $payload['village'] ?? $this->manualFields['city'];
        $this->manualFields['state'] = $payload['state'] ?? $this->manualFields['state'];
        $this->manualFields['postal_code'] = $payload['postal_code'] ?? $payload['postcode'] ?? $this->manualFields['postal_code'];
        $this->manualFields['country'] = $payload['country'] ?? $this->manualFields['country'] ?? 'Lietuva';
        $this->manualFields['country_code'] = Str::upper($payload['country_code'] ?? $payload['countryCode'] ?? $this->manualFields['country_code'] ?? 'LT');

        if (isset($payload['latitude']) && isset($payload['longitude'])) {
            $this->coordinates = [
                'latitude' => round((float) $payload['latitude'], 6),
                'longitude' => round((float) $payload['longitude'], 6),
            ];
        }

        $this->confidenceScore = isset($payload['confidence']) ? (float) $payload['confidence'] : $this->confidenceScore;

        $rawPayload = $payload['raw_payload']
            ?? $payload['raw_api_response']
            ?? Arr::get($payload, 'meta.raw')
            ?? Arr::get($payload, 'meta');

        if (is_array($rawPayload)) {
            $this->rawApiPayload = $rawPayload;
        }

        if (is_numeric($this->confidenceScore)) {
            $score = (float) $this->confidenceScore;
            $this->addressType = match (true) {
                $score >= 0.95 => AddressTypeEnum::VERIFIED,
                $score >= 0.6 => AddressTypeEnum::LOW_CONFIDENCE,
                default => AddressTypeEnum::UNVERIFIED,
            };
        }
    }

    private function finalizeConfirmation(InputModeEnum $mode): void
    {
        if (! is_numeric($this->coordinates['latitude']) || ! is_numeric($this->coordinates['longitude'])) {
            $this->pushMessage('errors', 'Koordinatės negalioja.');

            return;
        }

        $this->normalizeManualFields();
        if (empty($this->manualFields['formatted_address'])) {
            $this->manualFields['formatted_address'] = $this->buildDisplayAddress();
        }

        $this->inputMode = $mode;
        $this->currentState = AddressStateEnum::CONFIRMED;
        $this->snapshotAt = Carbon::now();
    }

    private function normalizeManualFields(): void
    {
        foreach ($this->manualFields as $field => $value) {
            if ($value === null) {
                continue;
            }

            if (is_string($value)) {
                $value = $this->sanitizeUtf8($value);
            }

            if ($value === null) {
                $this->manualFields[$field] = null;

                continue;
            }

            $trimmed = is_string($value) ? trim($value) : $value;
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

    private function buildDisplayAddress(): ?string
    {
        $parts = array_filter([
            trim((string) ($this->manualFields['street_name'] ?? '')),
            $this->manualFields['street_number'] ?? null,
            $this->manualFields['city'] ?? null,
            $this->manualFields['country'] ?? null,
        ], fn ($value) => filled($value));

        if ($parts === []) {
            return null;
        }

        return implode(', ', $parts);
    }

    private function sanitizeUtf8(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $clean = @iconv('UTF-8', 'UTF-8//IGNORE', $value);
        if ($clean === false) {
            return null;
        }

        return preg_replace('/[^\P{C}\n\t]/u', '', $clean) ?? null;
    }

    private function applySearchStatusWarnings(): void
    {
        if (! method_exists($this->geocodingService, 'getLastStatus')) {
            return;
        }

        $status = $this->geocodingService->getLastStatus('search');

        if ($status === 'rate_limited') {
            $this->pushMessage('warnings', __('address.rate_limited'));
        } elseif ($status === 'breaker_open') {
            $this->pushMessage('warnings', __('address.provider_offline'));
        }
    }
}
