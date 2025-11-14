<?php

declare(strict_types=1);

namespace App\Filament\Forms\Components;

use App\Enums\AddressStateEnum;
use App\Contracts\GeocodingServiceInterface;
use App\Enums\AddressTypeEnum;
use App\Enums\InputModeEnum;
use App\Services\AddressFormStateManager;
use App\Support\GeoNormalizer;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\LivewireField;
use Filament\Forms\Components\ViewField;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Kompozitinis Filament formos laukas, valdantis adreso paiešką, pasiūlymus,
 * rankinius laukus ir žemėlapio atvaizdavimą naudojant AddressFormStateManager.
 */
class AddressField extends Field
{
    /**
     * @var view-string
     */
    protected string $view = 'filament.forms.components.address-field';

    protected ?AddressFormStateManager $manager = null;

    protected ?string $configuredCountryCode = null;

    protected int $configuredMapHeight = 360;

    protected ?GeoNormalizer $normalizer = null;
    protected ?GeocodingServiceInterface $geocoder = null;

    protected function setUp(): void
    {
        parent::setUp();

        // Do not inject a default snapshot on create – let the user action
        // populate the state. Editing existing records can pass state explicitly.

        $this->childComponents($this->makeFieldComponents());

        $this->afterStateHydrated(function (AddressField $component, $state): void {
            if (config('app.debug')) {
                Log::debug('AddressField hydrating', [
                    'statePath' => $component->getStatePath(),
                    'state' => $state,
                ]);
            }

            // Only restore and apply if we actually have state (i.e. editing)
            if (is_array($state) && ! empty(array_filter($state))) {
                $manager = $component->resolveManager();
                $manager->restoreState($state);
                $component->applySnapshotFromManager();
            }
        });

        $this->dehydrateStateUsing(function (AddressField $component, $state): ?array {
            Log::debug('[TIME] dehydrateStateUsing START', [
                'time' => microtime(true),
                'statePath' => $component->getStatePath(),
                'has_manual_fields' => ! empty(data_get($state, 'manual_fields.city')),
            ]);

            // Always dehydrate current state to avoid race conditions between selection and submit.
            $manager = $component->resolveManager();

            if (is_array($state) && !empty(array_filter($state))) {
                $currentState = $component->getState();
                if (empty($state['address_type']) && ! empty(data_get($currentState, 'address_type'))) {
                    $state['address_type'] = data_get($currentState, 'address_type');
                }
                if (empty($state['current_state']) && ! empty(data_get($currentState, 'current_state'))) {
                    $state['current_state'] = data_get($currentState, 'current_state');
                }
                if (empty($state['confidence_score']) && data_get($currentState, 'confidence_score') !== null) {
                    $state['confidence_score'] = data_get($currentState, 'confidence_score');
                }

                $manager->restoreState($state);
            }

            if (app()->environment(['local', 'testing'])) {
                Log::info('addr:field:dehydrate:before', [
                    'statePath' => $component->getStatePath(),
                    'input_mode' => data_get($state, 'input_mode'),
                    'current_state' => data_get($state, 'current_state'),
                ]);
            }

            $manager->validateAndPrepareForSubmission();
            $component->applySnapshotFromManager();

            $snapshot = $manager->getStateSnapshot();

            if (app()->environment(['local', 'testing'])) {
                Log::info('addr:field:dehydrate:after', [
                    'statePath' => $component->getStatePath(),
                    'input_mode' => data_get($snapshot, 'input_mode'),
                    'current_state' => data_get($snapshot, 'current_state'),
                ]);
            }

            if (app()->environment(['local', 'testing'])) {
                Log::info('addr:field:dehydrate:final', [
                    'statePath' => $component->getStatePath(),
                    'snapshot' => $snapshot,
                ]);
            }

            if (config('app.debug')) {
                Log::debug('AddressField: dehydrateStateUsing COMPLETE', [
                    'statePath' => $component->getStatePath(),
                    'snapshot_FULL' => $snapshot,
                ]);
            }

            return $snapshot;
        });
    }

    public function countryCode(?string $countryCode): static
    {
        $this->configuredCountryCode = $countryCode ? strtolower($countryCode) : null;

        if ($countryCode) {
            $this->resolveManager()->setCountryCode($countryCode);
        }

        return $this;
    }

    public function mapHeight(int $height): static
    {
        $this->configuredMapHeight = max(200, $height);

        return $this;
    }

    public function stateManager(AddressFormStateManager $manager): static
    {
        $this->manager = $manager;

        if ($this->configuredCountryCode) {
            $this->manager->setCountryCode($this->configuredCountryCode);
        }

        return $this;
    }

    private function makeFieldComponents(): array
    {
        return [
            Hidden::make('current_state')->dehydrated(false)->reactive(),
            Hidden::make('input_mode')->dehydrated(false)->reactive(),
            Hidden::make('address_type')->dehydrated(false)->reactive(),
            Hidden::make('snapshot_at')->dehydrated(false)->reactive(),
            Hidden::make('search_query')->dehydrated(false)->reactive(),
            Hidden::make('suggestions')->dehydrated(false)->reactive(),
            Hidden::make('selected_suggestion')
                ->dehydrated(false)
                ->reactive()
                ->afterStateUpdated(function ($component, mixed $state): void {
                    if (! is_array($state) || empty($state['place_id'])) {
                        return;
                    }

                    $this->selectSuggestion($state);
                }),
            Hidden::make('selected_place_id')
                ->dehydrated(true)
                ->afterStateUpdated(function ($component, mixed $state): void {
                    if ($state === null || $state === '') {
                        return;
                    }

                    $this->handleSuggestionSelection((string) $state);
                }),
            Hidden::make('live.suggestions')
                ->dehydrated(false)
                ->reactive()
                ->afterStateUpdated(function ($component, mixed $state): void {
                    if (! is_array($state)) {
                        return;
                    }

                    $this->resolveManager()->handleSearchResults(collect($state));
                    $this->applySnapshotFromManager();
                }),
            Hidden::make('live.selected_place_id')
                ->dehydrated(false)
                ->reactive()
                ->afterStateUpdated(function ($component, mixed $state): void {
                    if (blank($state)) {
                        return;
                    }

                    $this->handleSuggestionSelection((string) $state);
                }),
            Hidden::make('live.selected_suggestion')
                ->dehydrated(false)
                ->reactive()
                ->afterStateUpdated(function ($component, mixed $state): void {
                    if (! is_array($state) || empty($state['place_id'])) {
                        return;
                    }

                    $this->selectSuggestion($state);
                }),
            Hidden::make('control.coordinates_sync_token')
                ->dehydrated(false)
                ->live()
                ->afterStateUpdated(function (): void {
                    $this->handleCoordinatesSync();
                }),
            Hidden::make('control.confirm_pin_token')
                ->dehydrated(false)
                ->reactive()
                ->afterStateUpdated(function ($component, mixed $state): void {
                    if (blank($state)) {
                        return;
                    }

                    Log::info('addr:field:pin:token-updated', [
                        'statePath' => $component->getStatePath(),
                        'token' => $state,
                    ]);

                    try {
                        $this->confirmPin();
                    } finally {
                        $control = (array) data_get($component->getState(), 'control', []);
                        $control['confirm_pin_token'] = null;
                        $component->state(['control' => $control]);
                    }
                }),
            Hidden::make('control.edit_mode_token')
                ->dehydrated(false)
                ->live()
                ->afterStateUpdated(function ($component, mixed $state): void {
                    if ($state === null) {
                        return;
                    }

                    $this->enterEditMode();

                    $control = (array) data_get($component->getState(), 'control', []);
                    $control['edit_mode_token'] = null;
                    $component->state(['control' => $control]);
                }),
            Hidden::make('ui.pin_confirming')->dehydrated(false)->reactive(),

            ViewField::make('summary_line')
                ->view('filament.forms.components.address-field.summary')
                ->viewData(
                    fn(Get $get): array => [
                        'manual' => $get('manual_fields') ?? [],
                        'coordinates' => $get('coordinates') ?? [],
                        'addressType' => $get('address_type') ?? null,
                        'statePath' => $this->getStatePath(),
                        'currentState' => $get('current_state'),
                        'editing' => data_get($get('ui') ?? [], 'editing', true),
                        'snapshotAt' => $get('snapshot_at'),
                    ],
                )
                ->columnSpanFull()
                ->dehydrated(false),

            Section::make()
                ->schema([
                    LivewireField::make('live')
                        ->dehydrated(false)
                        ->component(\App\Livewire\AddressSearchField::class)
                        ->data(
                            fn() => [
                                'countryCode' => $this->configuredCountryCode ?? 'lt',
                            ],
                        ),
                    ViewField::make('map')
                        ->view('filament.forms.components.address-field.map')
                        ->viewData(
                            fn(Get $get): array => [
                                'coordinates' => $get('coordinates') ?? [],
                                'statePath' => $this->getStatePath(),
                                'height' => $this->configuredMapHeight,
                            ],
                        )
                        ->dehydrated(false),
                ])
                ->columns([
                    'default' => 1,
                    'md' => 2,
                ])
                ->columnSpanFull(),

            ViewField::make('loading_indicator')
                ->view('filament.forms.components.address-field.loading')
                ->visible(
                    fn(Get $get): bool => $get('current_state') === AddressStateEnum::SEARCHING->value,
                )
                ->dehydrated(false)
                ->columnSpanFull(),

            ViewField::make('messages')
                ->view('filament.forms.components.address-field.messages')
                ->viewData(
                    fn(Get $get): array => [
                        'errors' => Arr::wrap(data_get($get('messages'), 'errors', [])),
                        'warnings' => Arr::wrap(data_get($get('messages'), 'warnings', [])),
                    ],
                )
                ->visible(
                    fn(Get $get): bool => filled(data_get($get('messages'), 'errors')) ||
                        filled(data_get($get('messages'), 'warnings')),
                )
                ->dehydrated(false)
                ->reactive()
                ->columnSpanFull(),

            ViewField::make('debug_info')
                ->dehydrated(false)
                ->reactive()
                ->visible(fn(): bool => (bool) config('app.debug'))
                ->view('filament.forms.components.address-field.debug')
                ->viewData(
                    fn(Get $get): array => [
                        'currentState' => $get('current_state'),
                        'inputMode' => $get('input_mode'),
                        'query' => $get('search_query') ?? '',
                        'suggestionsCount' => is_array($get('suggestions'))
                            ? count($get('suggestions'))
                            : 0,
                    ],
                )
                ->columnSpanFull(),
        ];
    }

    protected function resolveManager(): AddressFormStateManager
    {
        if (!$this->manager) {
            $this->manager = app(AddressFormStateManager::class);

            if ($this->configuredCountryCode) {
                $this->manager->setCountryCode($this->configuredCountryCode);
            }
        }

        return $this->manager;
    }

    protected function applySnapshotFromManager(bool $preserveSearch = false): void
    {
        $snapshot = $this->resolveManager()->getStateSnapshot();
        $current = $this->getState() ?? [];

        if ($preserveSearch) {
            $snapshot['search_query'] =
                $current['search_query'] ?? ($snapshot['search_query'] ?? '');
        } else {
            $snapshot['search_query'] = $snapshot['search_query'] ?? '';
        }

        $addressTypeValue = $snapshot['address_type']
            ?? $current['address_type']
            ?? AddressTypeEnum::UNVERIFIED->value;

        $currentStateValue = $snapshot['current_state'] ?? null;
        if (blank($currentStateValue) && $addressTypeValue !== AddressTypeEnum::UNVERIFIED->value) {
            $currentStateValue = AddressStateEnum::CONFIRMED->value;
        }

        $merged = array_replace($current, [
            'search_query' => $snapshot['search_query'],
            'current_state' => $currentStateValue ?? AddressStateEnum::IDLE->value,
            'input_mode' => $snapshot['input_mode'] ?? InputModeEnum::SEARCH->value,
            'address_type' => $addressTypeValue,
            'suggestions' => $snapshot['suggestions'] ?? [],
            'selected_suggestion' => $snapshot['selected_suggestion'] ?? null,
            'manual_fields' => array_replace(
                $current['manual_fields'] ?? [],
                $snapshot['manual_fields'] ?? [],
            ),
            'coordinates' => array_replace(
                $current['coordinates'] ?? [],
                $snapshot['coordinates'] ?? [],
            ),
            'snapshot_at' => $snapshot['snapshot_at'] ?? ($current['snapshot_at'] ?? null),
            'locked_fields' => $snapshot['locked_fields'] ?? [],
            'source_field_locks' => $snapshot['source_field_locks'] ?? [],
            'auto_select_alert' => $snapshot['auto_select_alert'] ?? false,
            'messages' => $snapshot['messages'] ?? ['errors' => [], 'warnings' => []],
            'confidence_score' => $snapshot['confidence_score'] ?? null,
            'raw_api_payload' => $snapshot['raw_api_payload'] ?? [],
            'selected_place_id' => $snapshot['selected_place_id'] ?? null,
        ]);

        $merged['control'] = array_replace($merged['control'] ?? [], [
            'switch_manual_token' => null,
            'undo_autoselect_token' => null,
            'coordinates_sync_token' => $merged['control']['coordinates_sync_token'] ?? null,
            'edit_mode_token' => null,
        ]);

        $merged['ui'] = array_replace(
            [
                'pin_confirming' => data_get($current, 'ui.pin_confirming', false),
                'editing' => data_get(
                    $current,
                    'ui.editing',
                    ($currentStateValue ?? AddressStateEnum::IDLE->value) !== AddressStateEnum::CONFIRMED->value,
                ),
            ],
            $current['ui'] ?? [],
        );

        $merged['live'] = array_replace($merged['live'] ?? [], [
            'search_query' => $snapshot['search_query'] ?? '',
            'suggestions' => $snapshot['suggestions'] ?? [],
            'selected_place_id' =>
                $snapshot['selected_suggestion']['place_id'] ??
                ($snapshot['selected_place_id'] ?? null),
            'selected_suggestion' => $snapshot['selected_suggestion'] ?? null,
        ]);

        $this->state($merged, false);
    }

    /**
     * Lighter snapshot application used during live search updates to avoid
     * re-rendering unrelated parts of the field and disrupting typing.
     */
    protected function applySnapshotFromManagerSearchOnly(): void
    {
        $snapshot = $this->resolveManager()->getStateSnapshot();
        $current = $this->getState() ?? [];

        $partial = array_replace($current, [
            'current_state' => $snapshot['current_state'] ?? AddressStateEnum::IDLE->value,
            'suggestions' => $snapshot['suggestions'] ?? [],
        ]);

        $partial['control'] = array_replace($partial['control'] ?? [], [
            'switch_manual_token' => null,
            'undo_autoselect_token' => null,
            'edit_mode_token' => null,
        ]);

        $partial['live'] = array_replace($partial['live'] ?? [], [
            'suggestions' => $snapshot['suggestions'] ?? [],
        ]);

        $this->state($partial, false);
    }

    protected function selectSuggestion(string|int|array|null $selection): void
    {
        if (is_array($selection)) {
            $mapped = $this->mapSuggestionPayload($selection);
            $this->ingestMappedSuggestion($mapped);

            return;
        }

        if ($selection === null || $selection === '') {
            return;
        }

        $this->handleSuggestionSelection((string) $selection);
    }

    protected function handleSuggestionSelection(string|int $placeId): void
    {
        $this->resolveManager()->selectSuggestion($placeId);
        $this->applySnapshotFromManager();
    }

    protected function handleCoordinatesSync(): void
    {
        $state = $this->getState() ?? [];
        $latitude = (float) data_get($state, 'coordinates.latitude', 0.0);
        $longitude = (float) data_get($state, 'coordinates.longitude', 0.0);

        if (config('app.debug')) {
            Log::debug('AddressField: handleCoordinatesSync:start', [
                'statePath' => $this->getStatePath(),
                'latitude' => $latitude,
                'longitude' => $longitude,
            ]);
        }

        $this->resolveManager()->updateCoordinates($latitude, $longitude, true);
        $this->applySnapshotFromManager();

        if (config('app.debug')) {
            Log::debug('AddressField: handleCoordinatesSync:end', [
                'statePath' => $this->getStatePath(),
                'state_coordinates' => data_get($this->getState(), 'coordinates'),
            ]);
        }
    }

    protected function confirmPin(): void
    {
        Log::debug('[TIME] confirmPin PHP START', [
            'time' => microtime(true),
        ]);

        $state = $this->getState() ?? [];
        $lat = data_get($state, 'coordinates.latitude');
        $lng = data_get($state, 'coordinates.longitude');

        Log::debug('CONFIRM PIN CALLED', [
            'coord_lat' => data_get($state, 'coordinates.latitude'),
            'coord_lng' => data_get($state, 'coordinates.longitude'),
        ]);

        if (!is_numeric($lat) || !is_numeric($lng)) {
            $this->resetControlToken('confirm_pin_token');

            return;
        }

        $latitude = (float) $lat;
        $longitude = (float) $lng;
        $mode = data_get($state, 'selected_suggestion.place_id')
            ? InputModeEnum::SEARCH
            : InputModeEnum::MANUAL;

        Log::info('addr:field:pin:invoke', [
            'statePath' => $this->getStatePath(),
            'addressType' => data_get($state, 'address_type'),
            'coordinates' => [
                'lat' => $latitude,
                'lng' => $longitude,
            ],
        ]);

        $this->setUiFlag('pin_confirming', true);

        try {
            try {
                $reverse = $this->getGeocoder()->reverse($latitude, $longitude);
            } catch (\Throwable $exception) {
                Log::warning('AddressField: reverse lookup failed', [
                    'lat' => $latitude,
                    'lng' => $longitude,
                    'message' => $exception->getMessage(),
                ]);
                $reverse = null;
            }

            if ($reverse) {
                $rawPayload = $reverse->meta['raw'] ?? ($reverse->meta['raw_payload'] ?? []);

                if (!is_array($rawPayload)) {
                    $rawPayload = [];
                }

                if (!isset($rawPayload['address']) && isset($reverse->meta['address'])) {
                    $rawPayload['address'] = $reverse->meta['address'];
                }

                $rawPayload = array_merge($rawPayload, [
                    'place_id' => $reverse->placeId,
                    'display_name' => $reverse->formattedAddress,
                    'lat' => $reverse->latitude,
                    'lon' => $reverse->longitude,
                ]);

                $mapped = $this->mapSuggestionPayload($rawPayload);
                $mapped['latitude'] = $reverse->latitude;
                $mapped['longitude'] = $reverse->longitude;

                $hasNumber = filled($mapped['street_number'] ?? null);
                $addressType = $hasNumber
                    ? AddressTypeEnum::VERIFIED
                    : AddressTypeEnum::LOW_CONFIDENCE;
                Log::info('addr:field:pin:reverse', [
                    'statePath' => $this->getStatePath(),
                    'addressType' => $addressType->value,
                    'city' => $mapped['city'] ?? null,
                    'street' => $mapped['street_name'] ?? null,
                    'number' => $mapped['street_number'] ?? null,
                ]);

                Log::debug('[STATE] BEFORE ingestPinResult', [
                    'statePath' => $this->getStatePath(),
                    'current_manual_fields' => data_get($this->getState(), 'manual_fields'),
                ]);

                $this->ingestPinResult(
                    $mapped,
                    $addressType,
                    $mode,
                );

                Log::debug('[STATE] AFTER ingestPinResult', [
                    'statePath' => $this->getStatePath(),
                    'new_manual_fields' => data_get($this->getState(), 'manual_fields'),
                ]);
            } else {
                Log::info('addr:field:pin:fallback', [
                    'statePath' => $this->getStatePath(),
                    'latitude' => $latitude,
                    'longitude' => $longitude,
                ]);

                Log::debug('[STATE] BEFORE ingestPinFallback', [
                    'statePath' => $this->getStatePath(),
                    'current_manual_fields' => data_get($this->getState(), 'manual_fields'),
                ]);

                $this->ingestPinFallback($latitude, $longitude, InputModeEnum::MANUAL);

                Log::debug('[STATE] AFTER ingestPinFallback', [
                    'statePath' => $this->getStatePath(),
                    'new_manual_fields' => data_get($this->getState(), 'manual_fields'),
                ]);
            }

            $this->markSelectionMade();
            $this->applySnapshotFromManager();
            $this->finalizeConfirmedSnapshot();

            if (config('app.debug')) {
                $finalState = $this->getState();
                Log::debug('confirmPin: final state after applySnapshot', [
                    'statePath' => $this->getStatePath(),
                    'manual_fields' => data_get($finalState, 'manual_fields'),
                    'coordinates' => data_get($finalState, 'coordinates'),
                    'address_type' => data_get($finalState, 'address_type'),
                ]);
            }
        } finally {
            $this->setUiFlag('pin_confirming', false);

            $this->resetControlToken('confirm_pin_token');
            $stateBeforeNotify = $this->getState();
            $snapshot = $this->resolveManager()->getStateSnapshot();
            $mergedState = $stateBeforeNotify ?? [];

            if (is_array($snapshot)) {
                $mergedState['address_type'] = $snapshot['address_type'] ?? ($mergedState['address_type'] ?? null);
                $mergedState['current_state'] = $snapshot['current_state'] ?? ($mergedState['current_state'] ?? null);
                $mergedState['confidence_score'] = $snapshot['confidence_score'] ?? ($mergedState['confidence_score'] ?? null);
            }

            Log::debug('[STATE] before state(..., true)', [
                'statePath' => $this->getStatePath(),
                'manual_fields' => data_get($mergedState, 'manual_fields'),
                'address_type' => $mergedState['address_type'] ?? null,
                'current_state' => $mergedState['current_state'] ?? null,
            ]);

            Log::debug('[FIX] state before notify with address_type', [
                'statePath' => $this->getStatePath(),
                'address_type' => $mergedState['address_type'] ?? null,
                'current_state' => $mergedState['current_state'] ?? null,
                'confidence_score' => $mergedState['confidence_score'] ?? null,
            ]);

            $this->state($mergedState, true);

            Log::debug('[STATE] after state(..., true) - should trigger Livewire', [
                'statePath' => $this->getStatePath(),
            ]);

            if (config('app.debug')) {
                $stateSnapshot = $this->getState();

                Log::debug('AddressField: after confirmPin', [
                    'statePath' => $this->getStatePath(),
                    'manual_fields' => data_get($stateSnapshot, 'manual_fields'),
                    'address_type' => data_get($stateSnapshot, 'address_type'),
                    'coordinates' => data_get($stateSnapshot, 'coordinates'),
                    'messages' => data_get($stateSnapshot, 'messages'),
                ]);
            }
        }

        Log::debug('[TIME] confirmPin PHP END', [
            'time' => microtime(true),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function mapSuggestionPayload(array $payload): array
    {
        $raw = $payload['raw_payload'] ?? ($payload['raw'] ?? null);
        if (!is_array($raw)) {
            $raw = [];
        }

        if (
            !isset($raw['address']) &&
            isset($payload['address']) &&
            is_array($payload['address'])
        ) {
            $raw['address'] = $payload['address'];
        }

        $placeId = (string) ($payload['place_id'] ?? ($raw['place_id'] ?? Str::uuid()->toString()));
        $displayName =
            $payload['display_name'] ??
            ($payload['formatted_address'] ?? ($raw['display_name'] ?? ''));

        $raw = array_merge($raw, [
            'place_id' => $placeId,
            'display_name' => $displayName,
            'lat' => $payload['latitude'] ?? ($payload['lat'] ?? ($raw['lat'] ?? null)),
            'lon' => $payload['longitude'] ?? ($payload['lon'] ?? ($raw['lon'] ?? null)),
        ]);

        $mapped = $this->getNormalizer()->mapProviderSuggestion($raw);

        $mapped['place_id'] = $placeId;
        $mapped['formatted_address'] =
            $payload['formatted_address'] ??
            ($mapped['formatted_address'] ?? ($mapped['short_address_line'] ?? $displayName));

        if (isset($payload['city'])) {
            $mapped['city'] = $payload['city'];
        }

        if (isset($payload['street_name'])) {
            $mapped['street_name'] = $payload['street_name'];
        }

        if (isset($payload['street_number'])) {
            $mapped['street_number'] = $payload['street_number'];
        }

        if (isset($payload['postal_code'])) {
            $mapped['postal_code'] = $payload['postal_code'];
        }

        if (isset($payload['country_code'])) {
            $mapped['country_code'] = Str::lower((string) $payload['country_code']);
        }

        $mapped['latitude'] =
            isset($payload['latitude']) && is_numeric($payload['latitude'])
                ? (float) $payload['latitude']
                : $mapped['latitude'] ?? null;
        $mapped['longitude'] =
            isset($payload['longitude']) && is_numeric($payload['longitude'])
                ? (float) $payload['longitude']
                : $mapped['longitude'] ?? null;

        if (isset($mapped['country_code'])) {
            $mapped['country_code'] = Str::lower((string) $mapped['country_code']);
        }

        if (!isset($mapped['street_name']) && isset($mapped['street'])) {
            $mapped['street_name'] = $mapped['street'];
        }

        if (!isset($mapped['street_number']) && isset($mapped['house_number'])) {
            $mapped['street_number'] = $mapped['house_number'];
        }

        return $mapped;
    }

    /**
     * @param  array<string, mixed>  $mapped
     */
    protected function ingestMappedSuggestion(array $mapped): void
    {
        $placeId = (string) ($mapped['place_id'] ?? Str::uuid()->toString());
        $mapped['place_id'] = $placeId;

        $manager = $this->resolveManager();
        $manager->handleSearchResults(collect([$mapped]));
        $manager->selectSuggestion($placeId);

        $hasStreet = filled($mapped['street_name'] ?? null);
        $hasNumber = filled($mapped['street_number'] ?? null);
        if ($hasStreet && !$hasNumber) {
            $manager->forceAddressType(AddressTypeEnum::LOW_CONFIDENCE);
        }

        $this->applySnapshotFromManager();
        $this->markSelectionMade();
    }

    protected function ingestPinResult(array $mapped, AddressTypeEnum $addressType, InputModeEnum $mode): void
    {
        Log::debug('[STATE] ingestPinResult received', [
            'statePath' => $this->getStatePath(),
            'mapped_city' => $mapped['city'] ?? null,
            'mapped_street' => $mapped['street_name'] ?? null,
            'mapped_number' => $mapped['street_number'] ?? null,
            'addressType' => $addressType->value,
        ]);

        $this->ingestMappedSuggestion($mapped);

        Log::debug('[STATE] after ingestMappedSuggestion', [
            'statePath' => $this->getStatePath(),
            'state_manual_fields' => data_get($this->getState(), 'manual_fields'),
        ]);

        $manager = $this->resolveManager();
        $manager->forceAddressType($addressType);
        $manager->markConfirmed($mode);

        Log::debug('[STATE] after manager operations', [
            'statePath' => $this->getStatePath(),
            'manager_snapshot_manual_fields' => data_get($manager->getStateSnapshot(), 'manual_fields'),
        ]);

        $this->applySnapshotFromManager();

        Log::debug('[STATE] after applySnapshotFromManager', [
            'statePath' => $this->getStatePath(),
            'final_state_manual_fields' => data_get($this->getState(), 'manual_fields'),
        ]);
    }

    protected function ingestPinFallback(float $latitude, float $longitude, InputModeEnum $mode): void
    {
        $formatted = sprintf('Koordinatės: %.6f, %.6f', $latitude, $longitude);

        $manager = $this->resolveManager();
        $manager->overwriteManualFields([
            'formatted_address' => $formatted,
            'street_name' => null,
            'street_number' => null,
            'city' => null,
            'postal_code' => null,
            'country_code' => null,
        ]);
        $manager->forceAddressType(AddressTypeEnum::VIRTUAL);
        $manager->updateCoordinates($latitude, $longitude, false);
        $manager->markConfirmed($mode);

        $this->applySnapshotFromManager();
    }

    protected function enterEditMode(): void
    {
        $this->resolveManager()->beginEditing();
        $this->applySnapshotFromManager(true);

        $state = $this->getState() ?? [];
        data_set($state, 'ui.editing', true);
        $this->state($state, false);
    }

    protected function finalizeConfirmedSnapshot(): void
    {
        $state = $this->getState() ?? [];
        data_set($state, 'ui.editing', false);

        $this->isApplyingSnapshot = true;
        $this->state($state, false);
        $this->isApplyingSnapshot = false;
    }

    protected function getNormalizer(): GeoNormalizer
    {
        if (!$this->normalizer) {
            $this->normalizer = app(GeoNormalizer::class);
        }

        return $this->normalizer;
    }

    protected function getGeocoder(): GeocodingServiceInterface
    {
        if (!$this->geocoder) {
            $this->geocoder = app(GeocodingServiceInterface::class);
        }

        return $this->geocoder;
    }

    protected function setUiFlag(string $flag, bool $value): void
    {
        $state = $this->getState() ?? [];
        data_set($state, "ui.{$flag}", $value);

        $this->isApplyingSnapshot = true;
        $this->state($state, false);
        $this->isApplyingSnapshot = false;
    }

    protected function markSelectionMade(): void
    {
        $state = $this->getState() ?? [];
        data_set($state, 'has_selection', true);

        $this->isApplyingSnapshot = true;
        $this->state($state, false);
        $this->isApplyingSnapshot = false;

        $this->resetSelectionErrors();
    }

    protected function resetSelectionErrors(): void
    {
        if (!method_exists($this, 'getLivewire')) {
            return;
        }

        $livewire = $this->getLivewire();

        if (!$livewire || !method_exists($livewire, 'resetErrorBag')) {
            return;
        }

        $livewire->resetErrorBag([
            'selected_suggestion',
            'address.search',
            'address.city',
            'address.street_name',
            'address.street_number',
            'address.formatted_address',
        ]);
    }

    protected function resetControlToken(string $token): void
    {
        $state = $this->getState() ?? [];
        data_set($state, "control.{$token}", null);

        $this->isApplyingSnapshot = true;
        $this->state($state, false);
        $this->isApplyingSnapshot = false;
    }

    // Search is handled by embedded Livewire field for UX stability.

    protected function isSourceFieldLocked(Get $get, string $field): bool
    {
        $locks = $get('source_field_locks');

        if (!is_array($locks)) {
            return false;
        }

        return in_array($field, $locks, true);
    }

    protected function getSourceLockHint(Get $get, string $field): ?string
    {
        return $this->isSourceFieldLocked($get, $field) ? '🔒 Locked by Source' : null;
    }
}
