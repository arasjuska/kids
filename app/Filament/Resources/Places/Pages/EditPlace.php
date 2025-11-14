<?php

namespace App\Filament\Resources\Places\Pages;

use App\Enums\AddressStateEnum;
use App\Enums\AddressTypeEnum;
use App\Enums\InputModeEnum;
use App\Filament\Resources\Places\Pages\Concerns\HandlesAddressSnapshots;
use App\Filament\Resources\Places\PlaceResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditPlace extends EditRecord
{
    protected static string $resource = PlaceResource::class;

    use HandlesAddressSnapshots;

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
                'snapshot_at' => optional($address->snapshotTimestamp())?->toIso8601String(),
            ];
        }

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $addressSnapshot = $data['address_state'] ?? [];
        unset($data['address_state']);

        $payload = $this->resolveAddressPayloadFromSnapshot($addressSnapshot);
        $payload['source_locked'] = false;
        $payload['override_reason'] = 'Address updated via map pin confirmation';

        $this->addressPayloadForUpdate = $payload;

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

}
