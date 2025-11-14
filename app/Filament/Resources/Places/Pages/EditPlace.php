<?php

namespace App\Filament\Resources\Places\Pages;

use App\Enums\AddressStateEnum;
use App\Enums\AddressTypeEnum;
use App\Enums\InputModeEnum;
use App\Filament\Resources\Places\PlaceResource;
use App\Http\Requests\AddressRequest;
use App\Services\AddressFormStateManager;
use App\Support\AddressPayloadBuilder;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class EditPlace extends EditRecord
{
    protected static string $resource = PlaceResource::class;

    protected ?array $addressPayloadForUpdate = null;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $this->record->loadMissing('address');

        $address = $this->record->address;
        if ($address) {
            $addressType =
                $address->address_type instanceof AddressTypeEnum
                    ? $address->address_type->value
                    : (is_string($address->address_type)
                        ? $address->address_type
                        : AddressTypeEnum::UNVERIFIED->value);

            $manualFields = [
                'formatted_address' => $address->formatted_address,
                'street_name' => $address->street_name,
                'street_number' => $address->street_number,
                'city' => $address->city,
                'state' => $address->state,
                'postal_code' => $address->postal_code,
                'country' => $address->country,
                'country_code' => $address->country_code,
            ];

            $data['address_state'] = [
                'coordinates' => [
                    'latitude' => $address->latitude,
                    'longitude' => $address->longitude,
                ],
                'manual_fields' => $manualFields,
                'address_type' => $addressType,
                'confidence_score' => $address->confidence_score,
                'current_state' =>
                    $addressType !== AddressTypeEnum::UNVERIFIED->value
                        ? AddressStateEnum::CONFIRMED->value
                        : AddressStateEnum::IDLE->value,
                'input_mode' => InputModeEnum::SEARCH->value,
                'raw_api_payload' => is_array($address->raw_api_response)
                    ? $address->raw_api_response
                    : [],
                'messages' => [
                    'errors' => [],
                    'warnings' => [],
                ],
                'control' => [],
                'locked_fields' => [],
                'source_field_locks' => [],
            ];
        }

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        Log::debug('[TIME] mutateFormDataBeforeSave', [
            'time' => microtime(true),
            'city' => $data['address_state']['manual_fields']['city'] ?? 'MISSING',
        ]);

        if (config('app.debug')) {
            Log::debug('EditPlace: mutateFormDataBeforeSave:start', [
                'address_state_FULL' => isset($data['address_state'])
                    ? json_encode(
                        $data['address_state'],
                        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE,
                    )
                    : null,
            ]);
        }

        $this->addressPayloadForUpdate = null;

        $addressState = $data['address_state'] ?? null;
        if (!is_array($addressState)) {
            return $data;
        }

        /** @var AddressFormStateManager $manager */
        $manager = app(AddressFormStateManager::class);
        $manager->restoreState($addressState);

        $validationResult = $manager->validateAndPrepareForSubmission();

        if (!empty($validationResult['errors'])) {
            throw ValidationException::withMessages([
                'address_state' => $validationResult['errors'],
            ]);
        }

        $normalized = AddressRequest::normalizePayload($validationResult['data']);
        $this->addressPayloadForUpdate = $this->buildAddressPayload($normalized);

        unset($data['address_state']);

        if (config('app.debug')) {
            Log::debug('EditPlace: transformed data', [
                'address' => $this->addressPayloadForUpdate,
            ]);
        }

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $updatedRecord = parent::handleRecordUpdate($record, $data);

        if ($this->addressPayloadForUpdate !== null) {
            $updatedRecord->loadMissing('address');
            $updatedRecord->saveAddress($this->addressPayloadForUpdate);
            $this->addressPayloadForUpdate = null;
        }

        return $updatedRecord;
    }

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }

    private function buildAddressPayload(array $addressData): array
    {
        $payload = AddressPayloadBuilder::fromNormalized($addressData);
        $payload['source_locked'] = false;
        $payload['override_reason'] = 'Address updated via map pin confirmation';

        return $payload;
    }
}
