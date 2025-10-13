<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\GeocodingServiceInterface;
use App\Enums\AddressStateEnum;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class AddressFormStateManager
{
    private AddressStateEnum $currentState = AddressStateEnum::INITIAL;
    private array $coordinates = ['latitude' => 54.8985, 'longitude' => 23.9036];
    private array $suggestions = [];
    private ?array $selectedAddress = null;

    public function __construct(
        protected GeocodingServiceInterface $geocodingService
    ) {}

    public function updateCoordinates(
        float $latitude,
        float $longitude,
        bool $performReverseGeocode = false
    ): void {
        $this->coordinates = [
            'latitude' => $latitude,
            'longitude' => $longitude,
        ];

        if ($performReverseGeocode) {
            $this->performReverseGeocoding();
        }
    }

    private function performReverseGeocoding(): void
    {
        $this->suggestions = [];
        $this->selectedAddress = null;
        $this->currentState = AddressStateEnum::LOADING;

        try {
            // Atliekame atvirkštinį geokodavimą
            $results = $this->geocodingService->reverse(
                $this->coordinates['latitude'],
                $this->coordinates['longitude']
            );

            // Jei rasta rezultatų, juos apdorojame ir nustatome SUGGESTIONS būseną
            if ($results->isNotEmpty()) {
                $this->handleSearchResults($results->toArray());
            } else {
                $this->currentState = AddressStateEnum::MANUAL_CONFIRMATION;
            }
        } catch (\Throwable $e) {
            Log::error('Reverse geocoding failed', ['error' => $e->getMessage()]);
            $this->currentState = AddressStateEnum::ERROR;
        }
    }

    // Metodas, naudojamas adreso paieškai iš AddressAutocomplete
    public function search(string $query, string $countryCode = 'lt'): void
    {
        $this->suggestions = [];
        $this->selectedAddress = null;
        $this->currentState = AddressStateEnum::LOADING;

        try {
            $results = $this->geocodingService->search($query, [
                'country_codes' => $countryCode,
                'limit' => 15,
            ]);

            $this->handleSearchResults($results->toArray());
        } catch (\Throwable $e) {
            Log::error('Address search failed', ['query' => $query, 'error' => $e->getMessage()]);
            $this->currentState = AddressStateEnum::ERROR;
        }
    }


    /**
     * @param array $results Rezultatai turi būti paprastas masyvas (array), o ne Collection.
     */
    private function handleSearchResults(array $results): void
    {
        // Čia turėtų būti rezultatų filtravimo/konvertavimo logika,
        // kuri juos pritaiko Filament formatui.
        // Dabar tiesiog paimame pirmąjį rezultatą kaip pavyzdį.

        $this->suggestions = $results;

        if (!empty($results)) {
            $this->currentState = AddressStateEnum::SUGGESTIONS;
        } else {
            $this->currentState = AddressStateEnum::MANUAL_CONFIRMATION;
        }
    }

    public function selectAddress(array $addressData): void
    {
        $this->selectedAddress = $addressData;
        $this->suggestions = [];
        $this->currentState = AddressStateEnum::SELECTED;

        if (isset($addressData['latitude'], $addressData['longitude'])) {
            $this->coordinates = [
                'latitude' => $addressData['latitude'],
                'longitude' => $addressData['longitude'],
            ];
        }
    }

    // Viešas metodas, kurio trūko. Naudojamas PlaceForm.php
    public function getAddressText(): string
    {
        if ($this->selectedAddress) {
            return $this->selectedAddress['short_address_line'] ?? $this->selectedAddress['formatted_address'] ?? 'Adresas pasirinktas';
        }

        // Jei yra pasiūlymų po reverse geocoding, grąžiname geriausią
        if ($this->currentState === AddressStateEnum::SUGGESTIONS && !empty($this->suggestions)) {
            $bestSuggestion = $this->suggestions[0];
            return $bestSuggestion['short_address_line'] ?? $bestSuggestion['formatted_address'] ?? 'Adresas rastas, patvirtinkite';
        }

        // Jei nieko nerasta, grąžiname koordinates
        return "{$this->coordinates['latitude']}, {$this->coordinates['longitude']}";
    }

    // Naudojamas PlaceForm.php
    public function getSuggestions(): array
    {
        return $this->suggestions;
    }

    // Naudojamas PlaceForm.php
    public function getCoordinates(): array
    {
        return $this->coordinates;
    }

    // Naudojamas AddressAutocomplete
    public function getAddressState(): AddressStateEnum
    {
        return $this->currentState;
    }
}
