<?php

declare(strict_types=1);

namespace App\Filament\Resources\Places\Pages;

use App\Filament\Resources\Places\PlaceResource;
use App\Http\Requests\AddressRequest;
use App\Http\Requests\PlaceRequest;
use App\Models\Address; // Importuojame AddressFormStateManager
use App\Rules\Utf8String;
use App\Services\AddressFormStateManager;
use App\Support\AddressPayloadBuilder;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CreatePlace extends CreateRecord
{
    protected static string $resource = PlaceResource::class;

    // Kadangi dabar naudojame AddressFormStateManager, šis kintamasis nebereikalingas
    // (bet palieku jį užkomentuotą, jei būtų kitų formos laukų, kurie jį naudoja).
    // public ?string $selected_address_data = null;

    /**
     * Nustato pradinius formos duomenis (kai puslapis užkraunamas).
     * Ši logika dabar perduodama AddressFormStateManager klasei.
     */
    protected function getInitialFormdata(): array
    {
        // Don’t prime address state on create – avoid saving defaults.
        // If debug flag is present on initial GET (?dd_address=1), persist it into state,
        // so it survives Livewire updates.
        $control = [];
        if (config('app.debug') && request()->boolean('dd_address')) {
            $control['__dd'] = true;
        }

        return [
            'name' => null,
            'address_state' => [
                'control' => $control,
            ],
        ];
    }

    /**
     * Mutuoja duomenis prieš įrašymą.
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (app()->environment(['local', 'testing', 'development'])) {
            logger()->info('addr.request.raw', [
                'all' => request()->all(),
                'query_hex' => collect(request()->query())
                    ->map(fn ($v) => is_string($v) ? bin2hex($v) : $v)
                    ->all(),
                'input_hex' => collect(request()->input())
                    ->map(fn ($v) => is_string($v) ? bin2hex($v) : $v)
                    ->all(),
            ]);
        }

        $addressSnapshot = $data['address_state'] ?? [];
        if (config('app.debug')) {
            \Log::debug('CreatePlace: snapshot received', $addressSnapshot);
        }
        unset($data['address_state']);

        $this->validateAddressFields($addressSnapshot);

        /** @var AddressFormStateManager $addressManager */
        $addressManager = app(AddressFormStateManager::class);
        $addressManager->restoreState($addressSnapshot);

        // Ensure coordinates reflect the selected suggestion if present in snapshot
        $selected = $addressSnapshot['selected_suggestion'] ?? null;
        $liveSelected = data_get($addressSnapshot, 'live.selected_suggestion');
        if (! is_array($selected) && is_array($liveSelected)) {
            $selected = $liveSelected;
        }
        if (is_array($selected)) {
            $sLat = $selected['latitude'] ?? null;
            $sLng = $selected['longitude'] ?? null;
            if (is_numeric($sLat) && is_numeric($sLng)) {
                $addressManager->updateCoordinates((float) $sLat, (float) $sLng, false);
            }
        }

        $validationResult = $addressManager->validateAndPrepareForSubmission();

        if (! empty($validationResult['errors'])) {
            // Show a visible notification to explain why save didn't proceed
            Notification::make()
                ->title('Nepavyko išsaugoti adreso')
                ->body(collect($validationResult['errors'])->implode("\n"))
                ->danger()
                ->seconds(10)
                ->send();

            throw ValidationException::withMessages([
                'address_state' => $validationResult['errors'],
            ]);
        }

        $addressData = AddressRequest::normalizePayload($validationResult['data']);

        // Final safety: compute precise lat/lng from multiple sources (prefer LIVE selection)
        $raw = $addressSnapshot['raw_api_payload'] ?? [];
        $rawFromData = $addressData['raw_api_response'] ?? null;
        $rLat = is_array($raw) ? ($raw['latitude'] ?? null) : null;
        $rLng = is_array($raw) ? ($raw['longitude'] ?? null) : null;
        $drLat = is_array($rawFromData) ? ($rawFromData['latitude'] ?? null) : null;
        $drLng = is_array($rawFromData) ? ($rawFromData['longitude'] ?? null) : null;
        $sLat = is_array($selected) ? ($selected['latitude'] ?? null) : null;
        $sLng = is_array($selected) ? ($selected['longitude'] ?? null) : null;
        $liveSel = data_get($addressSnapshot, 'live.selected_suggestion');
        $lsLat = is_array($liveSel) ? ($liveSel['latitude'] ?? null) : null;
        $lsLng = is_array($liveSel) ? ($liveSel['longitude'] ?? null) : null;

        $finalLat = null;
        $finalLng = null;
        if (is_numeric($lsLat) && is_numeric($lsLng)) {
            $finalLat = (float) $lsLat;
            $finalLng = (float) $lsLng;
        } elseif (is_numeric($sLat) && is_numeric($sLng)) {
            $finalLat = (float) $sLat;
            $finalLng = (float) $sLng;
        } elseif (is_numeric($rLat) && is_numeric($rLng)) {
            $finalLat = (float) $rLat;
            $finalLng = (float) $rLng;
        } elseif (is_numeric($drLat) && is_numeric($drLng)) {
            $finalLat = (float) $drLat;
            $finalLng = (float) $drLng;
        }

        // If nothing else provided, try to resolve coordinates from live.suggestions by selected_place_id
        if ($finalLat === null && $finalLng === null) {
            $suggestions = data_get($addressSnapshot, 'live.suggestions');
            $selectedPlaceId = data_get($addressSnapshot, 'live.selected_place_id')
                ?? data_get($addressSnapshot, 'selected_place_id');

            if (is_array($suggestions) && $selectedPlaceId) {
                foreach ($suggestions as $suggestion) {
                    if (! is_array($suggestion)) {
                        continue;
                    }

                    if ((string) ($suggestion['place_id'] ?? '') === (string) $selectedPlaceId) {
                        $sLat = $suggestion['latitude'] ?? null;
                        $sLng = $suggestion['longitude'] ?? null;
                        if (is_numeric($sLat) && is_numeric($sLng)) {
                            $finalLat = (float) $sLat;
                            $finalLng = (float) $sLng;
                        }
                        break;
                    }
                }
            }
        }

        if ($finalLat !== null && $finalLng !== null) {
            $addressData['latitude'] = round($finalLat, 6);
            $addressData['longitude'] = round($finalLng, 6);
        }

        // Fallback only: if we didn't get coords from raw/suggestion, use snapshot coordinates
        $snapLat = data_get($addressSnapshot, 'coordinates.latitude');
        $snapLng = data_get($addressSnapshot, 'coordinates.longitude');
        $defaultLat = 54.8985;
        $defaultLng = 23.9036;
        $snapHasValue = is_numeric($snapLat) && is_numeric($snapLng);
        $snapIsDefault = $snapHasValue
            ? (abs((float) $snapLat - $defaultLat) < 1e-6 && abs((float) $snapLng - $defaultLng) < 1e-6)
            : false;

        if ($finalLat === null && $snapHasValue && ! $snapIsDefault) {
            $addressData['latitude'] = round((float) $snapLat, 6);
            $addressData['longitude'] = round((float) $snapLng, 6);
        }
        if (config('app.debug')) {
            \Log::debug('CreatePlace: about to create Address', [
                'lat_from_data' => $addressData['latitude'] ?? null,
                'lng_from_data' => $addressData['longitude'] ?? null,
                'selected_lat' => is_array($selected ?? null) ? ($selected['latitude'] ?? null) : null,
                'selected_lng' => is_array($selected ?? null) ? ($selected['longitude'] ?? null) : null,
                'live_selected_lat' => is_array($liveSel ?? null) ? ($liveSel['latitude'] ?? null) : null,
                'live_selected_lng' => is_array($liveSel ?? null) ? ($liveSel['longitude'] ?? null) : null,
                'raw_lat' => is_array($raw ?? null) ? ($raw['latitude'] ?? null) : null,
                'raw_lng' => is_array($raw ?? null) ? ($raw['longitude'] ?? null) : null,
                'snapshot_lat' => $snapLat,
                'snapshot_lng' => $snapLng,
            ]);
        }

        // Optional debug: flag can come either from initial GET query persisted in state, or directly.
        $ddFlag = (bool) data_get($addressSnapshot, 'control.__dd');
        if (config('app.debug') && ($ddFlag || request()->boolean('dd_address'))) {
            $managerSnapshot = $addressManager->getStateSnapshot();
            $payload = [
                'addressData_final' => [
                    'lat' => $addressData['latitude'] ?? null,
                    'lng' => $addressData['longitude'] ?? null,
                ],
                'snapshot_coordinates' => [
                    'lat' => data_get($addressSnapshot, 'coordinates.latitude'),
                    'lng' => data_get($addressSnapshot, 'coordinates.longitude'),
                ],
                'selected_suggestion' => [
                    'lat' => is_array($selected ?? null) ? ($selected['latitude'] ?? null) : null,
                    'lng' => is_array($selected ?? null) ? ($selected['longitude'] ?? null) : null,
                    'place_id' => is_array($selected ?? null) ? ($selected['place_id'] ?? null) : null,
                ],
                'live_selected_suggestion' => [
                    'lat' => is_array($liveSel ?? null) ? ($liveSel['latitude'] ?? null) : null,
                    'lng' => is_array($liveSel ?? null) ? ($liveSel['longitude'] ?? null) : null,
                    'place_id' => is_array($liveSel ?? null) ? ($liveSel['place_id'] ?? null) : null,
                ],
                'raw_payload' => [
                    'lat' => is_array($raw ?? null) ? ($raw['latitude'] ?? null) : null,
                    'lng' => is_array($raw ?? null) ? ($raw['longitude'] ?? null) : null,
                ],
                'manager_snapshot_coordinates' => [
                    'lat' => data_get($managerSnapshot, 'coordinates.latitude'),
                    'lng' => data_get($managerSnapshot, 'coordinates.longitude'),
                ],
            ];

            // 1) Log for convenience
            \Log::debug('Address DD payload', $payload);

            // 2) Return an explicit JSON response to the Livewire request so you can
            //    inspect it in DevTools → Network → Response (works like dd for XHR).
            $response = response()->json(['__debug' => $payload], 418, [
                'X-Debug-Note' => 'Remove ?dd_address=1 to proceed normally.',
            ]);
            throw new HttpResponseException($response);
        }

        $addressSignature = $addressData['address_signature'] ?? null;

        $addressRecord = null;

        if ($addressSignature !== null) {
            $addressRecord = Address::query()
                ->where('address_signature', $addressSignature)
                ->first();
        }

        if (app()->environment(['local', 'testing', 'development'])) {
            logger()->info('addr.save.input', [
                'payload_utf8' => $addressData,
                'payload_hex' => collect($addressData)
                    ->map(fn ($value) => is_string($value) ? bin2hex($value) : $value)
                    ->all(),
            ]);
        }

        $addressPayload = AddressPayloadBuilder::fromNormalized($addressData);

        if ($addressRecord) {
            $addressRecord->fill($addressPayload);
            $addressRecord->save();
        } else {
            $addressPayload['address_signature'] = $addressSignature;
            $addressRecord = Address::create($addressPayload);
        }

        $data['address_id'] = $addressRecord->id;

        return PlaceRequest::normalizePayload($data);
    }

    protected function afterCreate(): void
    {
        $this->record->loadMissing('address');

        $formatted = optional($this->record->address)->formatted_address;

        if (! $formatted) {
            return;
        }

        Notification::make()
            ->title('Adresas išsaugotas')
            ->body("Adresą galite nukopijuoti:\n{$formatted}")
            ->success()
            ->seconds(10)
            ->send();
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function validateAddressFields(array $snapshot): void
    {
        $type = data_get($snapshot, 'address_type');
        $manual = data_get($snapshot, 'manual_fields', []);

        $rules = [
            'address_state.manual_fields.formatted_address' => ['nullable', new Utf8String()],
            'address_state.manual_fields.street_name' => [
                'nullable',
                new Utf8String(),
                Rule::requiredIf(fn () => in_array($type, ['verified', 'low_confidence'], true)),
            ],
            'address_state.manual_fields.street_number' => [
                'nullable',
                new Utf8String(),
                Rule::requiredIf(fn () => $type === 'verified'),
            ],
            'address_state.manual_fields.postal_code' => ['nullable', new Utf8String()],
            'address_state.manual_fields.city' => [
                'nullable',
                new Utf8String(),
                Rule::requiredIf(fn () => in_array($type, ['verified', 'low_confidence'], true)),
            ],
            'address_state.manual_fields.country_code' => ['nullable', new Utf8String()],
            'address_state.coordinates.latitude' => [
                'nullable',
                Rule::requiredIf(fn () => $type === 'virtual'),
                'numeric',
            ],
            'address_state.coordinates.longitude' => [
                'nullable',
                Rule::requiredIf(fn () => $type === 'virtual'),
                'numeric',
            ],
        ];

        $messages = [
            'address_state.manual_fields.city.required' => 'Miestas yra privalomas laukas.',
            'address_state.manual_fields.street_name.required' => 'Nenurodytas gatvės pavadinimas.',
            'address_state.manual_fields.street_number.required' => 'Nenurodytas namo numeris.',
            'address_state.coordinates.latitude.required' => 'Koordinatės privalomos virtualiam adresui.',
            'address_state.coordinates.longitude.required' => 'Koordinatės privalomos virtualiam adresui.',
        ];

        Validator::make(['address_state' => $snapshot], $rules, $messages)->validate();
    }
}
