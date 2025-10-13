<?php

namespace App\Filament\Resources\Places\Pages;

use App\Enums\AddressTypeEnum;
use App\Filament\Resources\Places\PlaceResource;
use App\Models\Address;
use App\Services\AddressFormStateManager;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Log;

class CreatePlace extends CreateRecord
{
    protected static string $resource = PlaceResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // 1. Inicijuojame AddressFormStateManager service
        /** @var AddressFormStateManager $addressManager */
        $addressManager = app(AddressFormStateManager::class);

        // Patikriname, ar AddressFormStateManager turi pilną adreso būseną
        $addressState = $addressManager->getAddressState();

        if (empty($addressState)) {
            Log::warning('AddressFormStateManager returned empty state. Creating UNVERIFIED address.', ['form_data' => $data]);

            // Jei būsena tuščia, bet koordinatės yra, kuriame virtualų adresą (saugiklis)
            $lat = $data['location']['latitude'] ?? 0.0;
            $lng = $data['location']['longitude'] ?? 0.0;
            $address = $this->createVirtualAddress($lat, $lng, $data);
            $data['address_id'] = $address->id;
        } else {
            // 2. Kuriame Address įrašą tiesiogiai iš AddressFormStateManager būsenos
            $address = $this->createAddressFromState($addressState, $data);
            $data['address_id'] = $address->id;
        }

        // Išvalome formos laukus, kurie nepatenka į Place modelį
        unset($data['location']);
        unset($data['full_address']);
        unset($data['coordinates_manually_changed']);
        unset($data['location_description']);
        // Išvalome visus laukus, kurie buvo naudojami tik formos būsenai valdyti, bet nėra Place modelio stulpeliai
        unset($data['address_details_block']);

        return $data;
    }

    /**
     * Sukuria Address įrašą iš galutinės AddressFormStateManager būsenos.
     * Tai apima tiek patvirtintus adresus, tiek virtualias vietas miške.
     */
    private function createAddressFromState(array $addressState, array $formData): Address
    {
        $lat = (float) $addressState['latitude'];
        $lng = (float) $addressState['longitude'];
        $isVirtual = $addressState['is_virtual'] ?? false;

        // Nustatome aprašymą
        $description = $formData['location_description'] ?? $addressState['description'] ?? null;

        // Jei yra virtualus, patiksliname aprašymą
        if ($isVirtual && empty($description)) {
            $description = 'Vieta pažymėta žemėlapyje be oficialaus adreso';
        }

        // Normalizuojame adreso tipą
        $addressType = $addressState['address_type'] ?? AddressTypeEnum::UNVERIFIED;
        if (is_string($addressType)) {
            $addressType = AddressTypeEnum::tryFrom($addressType) ?? AddressTypeEnum::UNVERIFIED;
        }

        return Address::create([
            'formatted_address' => $addressState['formatted_address'] ?? "Koordinatės: {$lat}, {$lng}",
            'street_name' => $addressState['street_name'] ?? null,
            'street_number' => $addressState['street_number'] ?? null,
            'city' => $addressState['city'] ?? null,
            'state' => $addressState['state'] ?? null,
            'postal_code' => $addressState['postal_code'] ?? null,
            'country' => $addressState['country'] ?? 'Lietuva',
            'country_code' => $addressState['country_code'] ?? 'LT',
            'latitude' => $lat,
            'longitude' => $lng,
            'address_type' => $addressType,
            'confidence_score' => $addressState['confidence_score'] ?? 0.0,
            'description' => $description,
            'raw_api_response' => $addressState['raw_api_response'] ?? null,
            'is_virtual' => $isVirtual,
        ]);
    }

    /**
     * Sukuria virtualų Address įrašą (saugiklis, kai AddressFormStateManager negrąžina būsenos)
     */
    private function createVirtualAddress(float $lat, float $lng, array $formData): Address
    {
        $description = $formData['location_description'] ?? 'Vieta pažymėta žemėlapyje be oficialaus adreso';

        return Address::create([
            'formatted_address' => "Vieta koordinatėse: " . round($lat, 6) . ", " . round($lng, 6),
            'street_name' => null,
            'street_number' => null,
            'city' => null,
            'state' => null,
            'postal_code' => null,
            'country' => 'Lietuva',
            'country_code' => 'LT',
            'latitude' => $lat,
            'longitude' => $lng,
            'address_type' => AddressTypeEnum::VIRTUAL,
            'confidence_score' => 0.0,
            'description' => $description,
            'raw_api_response' => null,
            'is_virtual' => true,
        ]);
    }
}
