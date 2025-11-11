<?php

declare(strict_types=1);

namespace App\Filament\Forms\Components;

use App\Enums\AddressStateEnum;
use App\Contracts\GeocodingServiceInterface;
use App\Enums\AddressTypeEnum;
use App\Enums\InputModeEnum;
use App\Rules\Utf8String;
use App\Services\AddressFormStateManager;
use App\Support\GeoNormalizer;
use App\Support\TextNormalizer;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\LivewireField;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ViewField;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;

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
            // Only restore and apply if we actually have state (i.e. editing)
            if (is_array($state) && ! empty(array_filter($state))) {
                $manager = $component->resolveManager();
                $manager->restoreState($state);
                $component->applySnapshotFromManager();
            }
        });

        $this->dehydrateStateUsing(function (AddressField $component, $state): ?array {
            // Always dehydrate current state to avoid race conditions between selection and submit.
            $manager = $component->resolveManager();

            if (is_array($state) && ! empty(array_filter($state))) {
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
            // Hidden reactive keys to ensure Livewire tracks these state changes
            Hidden::make('current_state')->dehydrated(false)->reactive(),
            Hidden::make('input_mode')->dehydrated(false)->reactive(),
            Hidden::make('address_type')->dehydrated(false)->reactive(),
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

            // Selected place id coming from Livewire search
            Hidden::make('selected_place_id')
                ->dehydrated(true)
                ->afterStateUpdated(function ($component, mixed $state): void {
                    if ($state === null || $state === '') {
                        return;
                    }

                    \Log::debug('AddressField: selected_place_id updated', ['value' => $state]);

                    $this->handleSuggestionSelection((string) $state);
                }),

            Hidden::make('live.suggestions')
                ->dehydrated(false)
                ->reactive()
                ->afterStateUpdated(function ($component, mixed $state): void {
                    if (! is_array($state)) {
                        return;
                    }
                    // Update manager suggestions so selectSuggestion can resolve place_id
                    \Log::debug('AddressField: live.suggestions updated', ['count' => count($state)]);
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

                    \Log::debug('AddressField: live.selected_place_id updated', ['value' => $state]);

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

            Hidden::make('control.switch_manual_token')
                ->dehydrated(false)
                ->live()
                ->afterStateUpdated(function ($component, mixed $state): void {
                    if ($state === null) {
                        return;
                    }

                    $this->handleSwitchToManual();
                }),

            Hidden::make('control.undo_autoselect_token')
                ->dehydrated(false)
                ->live()
                ->afterStateUpdated(function ($component, mixed $state): void {
                    if ($state === null) {
                        return;
                    }

                    $this->handleUndoAutoSelect();
                }),
            Hidden::make('control.confirm_pin_token')
                ->dehydrated(false)
                ->live()
                ->afterStateUpdated(function ($component, mixed $state): void {
                    if ($state === null) {
                        return;
                    }

                    $this->confirmPin();
                }),
            Hidden::make('ui.pin_confirming')
                ->dehydrated(false)
                ->reactive(),

            ViewField::make('summary_line')
                ->view('filament.forms.components.address-field.summary')
                ->viewData(fn (Get $get): array => [
                    'manual' => $get('manual_fields') ?? [],
                    'coordinates' => $get('coordinates') ?? [],
                    'addressType' => $get('address_type') ?? null,
                ])
                ->columnSpanFull()
                ->dehydrated(false),

            Section::make()
                ->schema([
                    LivewireField::make('live')
                        ->dehydrated(false)
                        ->component(\App\Livewire\AddressSearchField::class)
                        ->data(fn () => [
                            'countryCode' => $this->configuredCountryCode ?? 'lt',
                        ]),

                    ViewField::make('map')
                        ->view('filament.forms.components.address-field.map')
                        ->viewData(fn (Get $get): array => [
                            'coordinates' => $get('coordinates') ?? [],
                            'statePath' => $this->getStatePath(),
                            'height' => $this->configuredMapHeight,
                        ])
                        ->dehydrated(false),
                ])
                ->columns([
                    'default' => 1,
                    'md' => 2,
                ])
                ->columnSpanFull(),

            // Debug panel (visible only in APP_DEBUG)
            ViewField::make('debug_info')
                ->dehydrated(false)
                ->reactive()
                ->visible(fn (): bool => (bool) config('app.debug'))
                ->view('filament.forms.components.address-field.debug')
                ->viewData(fn (Get $get): array => [
                    'currentState' => $get('current_state'),
                    'inputMode' => $get('input_mode'),
                    'query' => $get('search_query') ?? '',
                    'suggestionsCount' => is_array($get('suggestions')) ? count($get('suggestions')) : 0,
                ])
                ->columnSpanFull(),

            ViewField::make('loading_indicator')
                ->view('filament.forms.components.address-field.loading')
                ->visible(fn (Get $get): bool => $get('current_state') === AddressStateEnum::SEARCHING->value)
                ->dehydrated(false)
                ->columnSpanFull(),

            // (inline dropdown is rendered inside search-inline view)

            // (Overlay dropdown restored; fallback select removed)

            ViewField::make('manual_switch_button')
                ->view('filament.forms.components.address-field.manual-actions')
                ->viewData(fn (Get $get): array => [
                    'mode' => $get('input_mode'),
                    'statePath' => $this->getStatePath(),
                    'showUndo' => (bool) $get('auto_select_alert'),
                ])
                ->dehydrated(false)
                ->columnSpanFull(),

            Select::make('selected_place_id')
                ->label('Galimi adresai')
                ->options(fn (Get $get): array => $this->formatSuggestionOptions($get('suggestions')))
                ->dehydrated(false)
                ->visible(fn (Get $get): bool => $get('current_state') === AddressStateEnum::SUGGESTIONS->value)
                ->afterStateUpdated(function ($component, ?string $state): void {
                    if (! $state) {
                        return;
                    }

                    $this->handleSuggestionSelection($state);
                })
                ->native(false)
                ->searchable()
                ->columnSpanFull(),

            ViewField::make('undo_auto_select_notice')
                ->view('filament.forms.components.address-field.undo-auto-select')
                ->visible(fn (Get $get): bool => (bool) $get('auto_select_alert'))
                ->viewData(fn (Get $get): array => [
                    'statePath' => $this->getStatePath(),
                ])
                ->dehydrated(false)
                ->columnSpanFull(),

            ViewField::make('messages')
                ->view('filament.forms.components.address-field.messages')
                ->viewData(fn (Get $get): array => [
                    'errors' => Arr::wrap(data_get($get('messages'), 'errors', [])),
                    'warnings' => Arr::wrap(data_get($get('messages'), 'warnings', [])),
                ])
                ->visible(fn (Get $get): bool => filled(data_get($get('messages'), 'errors')) || filled(data_get($get('messages'), 'warnings')))
                ->dehydrated(false)
                ->reactive()
                ->columnSpanFull(),

            Section::make('Adreso duomenys')
                ->schema([
                    TextInput::make('manual_fields.formatted_address')
                        ->label('Pilnas adresas')
                        ->columnSpanFull()
                        ->rule(new Utf8String())
                        ->afterStateUpdated(fn ($component, ?string $state) => $this->handleManualFieldUpdate('formatted_address', $state))
                        ->live(onBlur: true),
                    TextInput::make('manual_fields.street_name')
                        ->label('Gatvė')
                        ->disabled(fn (Get $get): bool => $this->isSourceFieldLocked($get, 'street_name'))
                        ->hint(fn (Get $get): ?string => $this->getSourceLockHint($get, 'street_name'))
                        ->rule(new Utf8String())
                        ->afterStateUpdated(fn ($component, ?string $state) => $this->handleManualFieldUpdate('street_name', $state))
                        ->live(onBlur: true),
                    TextInput::make('manual_fields.street_number')
                        ->label('Namo Nr.')
                        ->disabled(fn (Get $get): bool => $this->isSourceFieldLocked($get, 'street_number'))
                        ->hint(fn (Get $get): ?string => $this->getSourceLockHint($get, 'street_number'))
                        ->rule(new Utf8String())
                        ->afterStateUpdated(fn ($component, ?string $state) => $this->handleManualFieldUpdate('street_number', $state))
                        ->live(onBlur: true),
                    TextInput::make('manual_fields.postal_code')
                        ->label('Pašto kodas')
                        ->disabled(fn (Get $get): bool => $this->isSourceFieldLocked($get, 'postal_code'))
                        ->hint(fn (Get $get): ?string => $this->getSourceLockHint($get, 'postal_code'))
                        ->rule(new Utf8String())
                        ->afterStateUpdated(fn ($component, ?string $state) => $this->handleManualFieldUpdate('postal_code', $state))
                        ->live(onBlur: true),
                    TextInput::make('manual_fields.city')
                        ->label('Miestas')
                        ->disabled(fn (Get $get): bool => $this->isSourceFieldLocked($get, 'city'))
                        ->hint(fn (Get $get): ?string => $this->getSourceLockHint($get, 'city'))
                        ->rule(new Utf8String())
                        ->afterStateUpdated(fn ($component, ?string $state) => $this->handleManualFieldUpdate('city', $state))
                        ->live(onBlur: true),
                    TextInput::make('manual_fields.country_code')
                        ->label('Šalies kodas')
                        ->maxLength(2)
                        ->disabled(fn (Get $get): bool => $this->isSourceFieldLocked($get, 'country_code'))
                        ->hint(fn (Get $get): ?string => $this->getSourceLockHint($get, 'country_code'))
                        ->rule(new Utf8String())
                        ->afterStateUpdated(fn ($component, ?string $state) => $this->handleManualFieldUpdate('country_code', $state))
                        ->live(onBlur: true),
                ])
                ->columns(2)
                ->visible(fn (Get $get): bool => in_array($get('input_mode'), [InputModeEnum::MANUAL->value, InputModeEnum::MIXED->value], true))
                ->columnSpanFull(),

            ViewField::make('manual_hint')
                ->visible(fn (Get $get): bool => filled(data_get($get('manual_fields'), 'street_name')) || filled(data_get($get('manual_fields'), 'city')) || filled(data_get($get('manual_fields'), 'postal_code')) || filled(data_get($get('manual_fields'), 'street_number')))
                ->view('filament.forms.components.address-field.manual-hint')
                ->columnSpanFull()
                ->dehydrated(false),

            // Map moved next to the search input above
        ];
    }

    protected function resolveManager(): AddressFormStateManager
    {
        if (! $this->manager) {
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
            $snapshot['search_query'] = $current['search_query'] ?? ($snapshot['search_query'] ?? '');
        } else {
            $snapshot['search_query'] = $snapshot['search_query'] ?? '';
        }

        $merged = array_replace($current, [
            'search_query' => $snapshot['search_query'],
            'current_state' => $snapshot['current_state'] ?? AddressStateEnum::IDLE->value,
            'input_mode' => $snapshot['input_mode'] ?? InputModeEnum::SEARCH->value,
            'address_type' => $snapshot['address_type'] ?? null,
            'suggestions' => $snapshot['suggestions'] ?? [],
            'selected_suggestion' => $snapshot['selected_suggestion'] ?? null,
            'manual_fields' => array_replace($current['manual_fields'] ?? [], $snapshot['manual_fields'] ?? []),
            'coordinates' => array_replace($current['coordinates'] ?? [], $snapshot['coordinates'] ?? []),
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
        ]);

        $merged['ui'] = array_replace([
            'pin_confirming' => data_get($current, 'ui.pin_confirming', false),
        ], $current['ui'] ?? []);

        $merged['live'] = array_replace($merged['live'] ?? [], [
            'search_query' => $snapshot['search_query'] ?? '',
            'suggestions' => $snapshot['suggestions'] ?? [],
            'selected_place_id' => $snapshot['selected_suggestion']['place_id'] ?? $snapshot['selected_place_id'] ?? null,
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

    protected function handleUndoAutoSelect(): void
    {
        $this->resolveManager()->undoAutoSelect();
        $this->applySnapshotFromManager();
    }

    protected function handleSwitchToManual(): void
    {
        $this->resolveManager()->switchToManualMode();
        $this->applySnapshotFromManager();
    }

    protected function handleManualFieldUpdate(string $field, mixed $value): void
    {
        $manager = $this->resolveManager();

        try {
            $normalized = $this->normalizeManualInput($field, $value);
            $manager->updateManualField($field, $normalized);
            $manager->validateAndPrepareForSubmission();
        } catch (InvalidArgumentException $exception) {
            $manager->pushMessage('errors', $exception->getMessage());

            if (app()->environment(['local', 'testing'])) {
                Log::warning('AddressField: locked field update blocked', [
                    'field' => $field,
                    'statePath' => $this->getStatePath(),
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        $this->applySnapshotFromManager();
    }

    protected function normalizeManualInput(string $field, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            $normalized = TextNormalizer::toNfc($value);

            if ($field === 'country_code') {
                return $normalized !== null ? Str::lower($normalized) : null;
            }

            return $normalized;
        }

        return $value;
    }

    protected function handleCoordinatesSync(): void
    {
        $state = $this->getState() ?? [];
        $latitude = (float) data_get($state, 'coordinates.latitude', 0.0);
        $longitude = (float) data_get($state, 'coordinates.longitude', 0.0);

        $this->resolveManager()->updateCoordinates($latitude, $longitude, true);
        $this->applySnapshotFromManager();
    }

    protected function confirmPin(): void
    {
        $state = $this->getState() ?? [];
        $lat = data_get($state, 'pin.latitude');
        $lng = data_get($state, 'pin.longitude');

        if (! is_numeric($lat) || ! is_numeric($lng)) {
            $this->resetControlToken('confirm_pin_token');

            return;
        }

        $latitude = (float) $lat;
        $longitude = (float) $lng;

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
            $rawPayload = $reverse->meta['raw'] ?? $reverse->meta['raw_payload'] ?? [];

                if (! is_array($rawPayload)) {
                    $rawPayload = [];
                }

                if (! isset($rawPayload['address']) && isset($reverse->meta['address'])) {
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
            $this->ingestPinResult($mapped, $hasNumber ? AddressTypeEnum::VERIFIED : AddressTypeEnum::LOW_CONFIDENCE);
        } else {
            $this->ingestPinFallback($latitude, $longitude);
        }

        $this->markSelectionMade();
        } finally {
            $this->setUiFlag('pin_confirming', false);

            $this->togglePinMode('search');
            $this->resetControlToken('confirm_pin_token');
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function mapSuggestionPayload(array $payload): array
    {
        $raw = $payload['raw_payload'] ?? $payload['raw'] ?? null;
        if (! is_array($raw)) {
            $raw = [];
        }

        if (! isset($raw['address']) && isset($payload['address']) && is_array($payload['address'])) {
            $raw['address'] = $payload['address'];
        }

        $placeId = (string) ($payload['place_id'] ?? ($raw['place_id'] ?? Str::uuid()->toString()));
        $displayName = $payload['display_name'] ?? $payload['formatted_address'] ?? ($raw['display_name'] ?? '');

        $raw = array_merge($raw, [
            'place_id' => $placeId,
            'display_name' => $displayName,
            'lat' => $payload['latitude'] ?? $payload['lat'] ?? ($raw['lat'] ?? null),
            'lon' => $payload['longitude'] ?? $payload['lon'] ?? ($raw['lon'] ?? null),
        ]);

        $mapped = $this->getNormalizer()->mapProviderSuggestion($raw);

        $mapped['place_id'] = $placeId;
        $mapped['formatted_address'] = $payload['formatted_address']
            ?? ($mapped['formatted_address'] ?? $mapped['short_address_line'] ?? $displayName);

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

        $mapped['latitude'] = isset($payload['latitude']) && is_numeric($payload['latitude'])
            ? (float) $payload['latitude']
            : ($mapped['latitude'] ?? null);
        $mapped['longitude'] = isset($payload['longitude']) && is_numeric($payload['longitude'])
            ? (float) $payload['longitude']
            : ($mapped['longitude'] ?? null);

        if (isset($mapped['country_code'])) {
            $mapped['country_code'] = Str::lower((string) $mapped['country_code']);
        }

        if (! isset($mapped['street_name']) && isset($mapped['street'])) {
            $mapped['street_name'] = $mapped['street'];
        }

        if (! isset($mapped['street_number']) && isset($mapped['house_number'])) {
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
        if ($hasStreet && ! $hasNumber) {
            $manager->forceAddressType(AddressTypeEnum::LOW_CONFIDENCE);
        }

        $this->applySnapshotFromManager();
        $this->markSelectionMade();
    }

    protected function ingestPinResult(array $mapped, AddressTypeEnum $addressType): void
    {
        $this->ingestMappedSuggestion($mapped);

        $manager = $this->resolveManager();
        $manager->forceAddressType($addressType);
        $manager->markConfirmed();
        $this->applySnapshotFromManager();
    }

    protected function ingestPinFallback(float $latitude, float $longitude): void
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
        $manager->markConfirmed();

        $this->applySnapshotFromManager();
    }

    protected function getNormalizer(): GeoNormalizer
    {
        if (! $this->normalizer) {
            $this->normalizer = app(GeoNormalizer::class);
        }

        return $this->normalizer;
    }

    protected function getGeocoder(): GeocodingServiceInterface
    {
        if (! $this->geocoder) {
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
        if (! method_exists($this, 'getLivewire')) {
            return;
        }

        $livewire = $this->getLivewire();

        if (! $livewire || ! method_exists($livewire, 'resetErrorBag')) {
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

    /**
     * @param  array<int, array<string, mixed>>|null  $suggestions
     */
    protected function formatSuggestionOptions(?array $suggestions): array
    {
        if (empty($suggestions)) {
            return [];
        }

        return collect($suggestions)
            ->filter(fn (array $item): bool => isset($item['place_id']))
            ->mapWithKeys(fn (array $item): array => [
                $item['place_id'] => $item['short_address_line']
                    ?? $item['formatted_address']
                    ?? sprintf('%s, %s', $item['street_name'] ?? 'Nežinoma', $item['city'] ?? ''),
            ])
            ->all();
    }

    // Search is handled by embedded Livewire field for UX stability.

    protected function isSourceFieldLocked(Get $get, string $field): bool
    {
        $locks = $get('source_field_locks');

        if (! is_array($locks)) {
            return false;
        }

        return in_array($field, $locks, true);
    }

    protected function getSourceLockHint(Get $get, string $field): ?string
    {
        return $this->isSourceFieldLocked($get, $field)
            ? '🔒 Locked by Source'
            : null;
    }
}
