<?php

declare(strict_types=1);

namespace App\Filament\Forms\Components;

use App\Enums\AddressStateEnum;
use App\Enums\InputModeEnum;
use App\Services\AddressFormStateManager;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\LivewireField;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ViewField;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Arr;

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

            $manager->validateAndPrepareForSubmission();
            $component->applySnapshotFromManager();

            return $manager->getStateSnapshot();
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

                    $manager = $this->resolveManager();
                    $manager->handleSearchResults(collect([$state]));
                    $manager->selectSuggestion((string) $state['place_id']);
                    $this->applySnapshotFromManager();
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

                    $manager = $this->resolveManager();
                    $manager->handleSearchResults(collect([$state]));
                    $manager->selectSuggestion((string) $state['place_id']);
                    $this->applySnapshotFromManager();
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
                        ->afterStateUpdated(fn ($component, ?string $state) => $this->handleManualFieldUpdate('formatted_address', $state))
                        ->live(onBlur: true),
                    TextInput::make('manual_fields.street_name')
                        ->label('Gatvė')
                        ->afterStateUpdated(fn ($component, ?string $state) => $this->handleManualFieldUpdate('street_name', $state))
                        ->live(onBlur: true),
                    TextInput::make('manual_fields.street_number')
                        ->label('Namo Nr.')
                        ->afterStateUpdated(fn ($component, ?string $state) => $this->handleManualFieldUpdate('street_number', $state))
                        ->live(onBlur: true),
                    TextInput::make('manual_fields.postal_code')
                        ->label('Pašto kodas')
                        ->afterStateUpdated(fn ($component, ?string $state) => $this->handleManualFieldUpdate('postal_code', $state))
                        ->live(onBlur: true),
                    TextInput::make('manual_fields.city')
                        ->label('Miestas')
                        ->afterStateUpdated(fn ($component, ?string $state) => $this->handleManualFieldUpdate('city', $state))
                        ->live(onBlur: true),
                    TextInput::make('manual_fields.country_code')
                        ->label('Šalies kodas')
                        ->maxLength(2)
                        ->afterStateUpdated(fn ($component, ?string $state) => $this->handleManualFieldUpdate('country_code', $state))
                        ->live(onBlur: true),
                ])
                ->columns(2)
                ->visible(fn (Get $get): bool => in_array($get('input_mode'), [InputModeEnum::MANUAL->value, InputModeEnum::MIXED->value], true))
                ->columnSpanFull(),

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
            'manual_fields' => $snapshot['manual_fields'] ?? [],
            'coordinates' => $snapshot['coordinates'] ?? [],
            'locked_fields' => $snapshot['locked_fields'] ?? [],
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
        $this->resolveManager()->updateManualField($field, $value);
        $this->applySnapshotFromManager();
    }

    protected function handleCoordinatesSync(): void
    {
        $state = $this->getState() ?? [];
        $latitude = (float) data_get($state, 'coordinates.latitude', 0.0);
        $longitude = (float) data_get($state, 'coordinates.longitude', 0.0);

        $this->resolveManager()->updateCoordinates($latitude, $longitude, true);
        $this->applySnapshotFromManager();
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
}
