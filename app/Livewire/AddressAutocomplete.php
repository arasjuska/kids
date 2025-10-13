<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Contracts\GeocodingServiceInterface;
use App\Enums\AddressTypeEnum;
use Illuminate\Support\Facades\Log;
use Livewire\Component;
use Illuminate\Support\Collection; // Importuotas Collection

class AddressAutocomplete extends Component
{
    // KONSTANTOS - ATITINKA AddressFormStateManager
    private const CONFIDENCE_VERIFIED = 0.85;
    private const CONFIDENCE_LOW = 0.6;
    private const CONFIDENCE_AUTOSELECT = 0.95;

    public string $statePath = '';
    public string $placeholder = 'Įveskite adresą...';
    public string $countryCode = 'lt';
    public int $minSearchLength = 3;

    public string $query = '';
    public array $suggestions = [];
    public bool $isLoading = false;
    public bool $showSuggestions = false;
    public ?string $error = null;
    public int $selectedIndex = -1;
    public array $selectedAddress = [];

    protected array $queryString = ['query'];

    // NAUJAS: Pridėtas Livewire Listener'is, kuris bus kviečiamas po žemėlapio atnaujinimo
    protected $listeners = [
        // 'addressSelected' bus kviečiamas po pasirinkimo
        'mapUpdated' => 'handleMapUpdate',
        'updateAddressQuery' => 'handleMapUpdate', // Alternatyvus pavadinimas, jei forma siunčia kitą event'ą
    ];

    public function mount(
        string $statePath = '',
        string $placeholder = 'Įveskite adresą...',
        string $countryCode = 'lt',
        array $initialData = []
    ): void {
        $this->statePath = $statePath;
        $this->placeholder = $placeholder;
        $this->countryCode = $countryCode;

        if (!empty($initialData)) {
            $this->selectedAddress = $initialData;
            $this->query = $initialData['short_address_line']
                ?? $initialData['formatted_address']
                ?? '';
        }
    }

    /**
     * NAUJAS METODAS: Paleidžiamas, kai žemėlapis atnaujina tėvinės formos būseną (po PIN judėjimo).
     * @param string $newQuery Naujas adreso tekstas.
     * @param bool $performSearch Ar priverstinai paleisti paiešką (atidaryti dropdown'ą).
     */
    public function handleMapUpdate(string $newQuery, bool $performSearch = true): void
    {
        // Pirmiausia atnaujiname vidinę $query vertę
        $this->query = $newQuery;

        // Jei jau rodomi pasiūlymai ir užklausa nepasikeitė, nieko nedarome
        if (trim($this->query) === trim($newQuery) && $this->showSuggestions) {
            return;
        }

        // Išvalome anksčiau pasirinktą adresą, nes tai nauja paieška iš žemėlapio
        if (!empty($this->selectedAddress)) {
            $this->selectedAddress = [];
            $this->dispatchStateUpdate([]);
        }

        // Jei performSearch yra true (t.y., po reverse geocoding), ieškome
        if ($performSearch) {
            $this->performSearch();
        } else {
            $this->resetSuggestions();
        }
    }

    public function updatedQuery(): void
    {
        $this->selectedIndex = -1;
        $this->error = null;

        // Jei turime pasirinktą adresą ir query pasikeičia - išvalome
        if (!empty($this->selectedAddress)) {
            $this->selectedAddress = [];
            $this->dispatchStateUpdate([]);
        }

        $trimmed = trim($this->query);
        if (strlen($trimmed) < $this->minSearchLength) {
            $this->resetSuggestions();
            return;
        }

        $this->performSearch();
    }

    public function openDropdownOnFocus(): void
    {
        if (!empty($this->selectedAddress)) {
            return;
        }

        $trimmed = trim($this->query);
        if (strlen($trimmed) < $this->minSearchLength) {
            return;
        }

        if (!empty($this->suggestions)) {
            $this->showSuggestions = true;
            return;
        }

        if (!$this->isLoading) {
            $this->showSuggestions = true;
            $this->performSearch();
        }
    }

    public function performSearch(): void
    {
        $trimmed = trim($this->query);
        if (strlen($trimmed) < $this->minSearchLength) {
            $this->resetSuggestions();
            return;
        }

        $this->isLoading = true;
        $this->error = null;

        try {
            $geocodingService = app(GeocodingServiceInterface::class);
            $results = $geocodingService->search($trimmed, [
                'country_codes' => $this->countryCode,
                'limit' => 15,
            ]);

            // Pataisymas: Paverčiame kolekciją į masyvą
            if ($results instanceof Collection) {
                $results = $results->toArray();
            }

            $this->suggestions = $results;
            $this->showSuggestions = true;

            // Bandome automatiškai pasirinkti labai tikslų rezultatą
            $this->attemptAutoselect($results, $trimmed);
        } catch (\Throwable $e) {
            Log::error('Address search failed in AddressAutocomplete', [
                'query' => $trimmed,
                'error' => $e->getMessage()
            ]);
            $this->error = 'Paieška nepavyko. Bandykite dar kartą.';
            $this->resetSuggestions();
        } finally {
            $this->isLoading = false;
        }
    }

    private function attemptAutoselect(array $results, string $query): void
    {
        // Naudojame collect() tik šioje vietoje, kad būtų saugu naudoti Collection metodus
        $resultsCollection = collect($results);

        $highConfidenceIndex = $resultsCollection->search(function ($result) {
            return ($result['confidence'] ?? 0.0) >= self::CONFIDENCE_AUTOSELECT;
        });

        if ($highConfidenceIndex === false) {
            return;
        }

        $highConfidenceResult = $resultsCollection->get($highConfidenceIndex);

        // Patikrinti, ar įvestis atrodo pilna (su namo numeriu)
        if ($this->queryLooksComplete($query, $highConfidenceResult)) {
            $this->selectSuggestion($highConfidenceIndex);
            $this->showSuggestions = false;
        }
    }

    private function queryLooksComplete(string $query, array $result): bool
    {
        // Patikra 1: Ar įvestyje yra skaičius?
        if (!preg_match('/\d/', $query)) {
            return false;
        }

        // Patikra 2: Ar įvestis ilgesnė nei tik gatvės pavadinimas?
        $streetName = $result['street_name'] ?? '';
        if (empty($streetName)) {
            return true; // Jei nėra gatvės pavadinimo, laikome pilnu
        }

        // Normalizuojame įvestį ir gatvės pavadinimą palyginimui
        $normalizedQuery = mb_strtolower(trim($query));
        $normalizedStreet = mb_strtolower(trim($streetName));

        // Jei įvestis žymiai ilgesnė nei gatvės pavadinimas - tikėtina, kad pilnas
        return strlen($normalizedQuery) > strlen($normalizedStreet) + 2;
    }

    public function selectSuggestion(int $index): void
    {
        if (!isset($this->suggestions[$index])) {
            return;
        }

        $selected = $this->suggestions[$index];
        $confidence = $selected['confidence'] ?? 0.0;

        // Nustatome adreso tipą pagal confidence
        $addressType = $this->determineAddressTypeByConfidence($confidence);
        $typeEnum = AddressTypeEnum::tryFrom($addressType) ?? AddressTypeEnum::UNVERIFIED;

        $addressData = [
            'short_address_line' => $selected['short_address_line']
                ?? ($selected['formatted_address'] ?? ''),
            'context_line' => $selected['context_line'] ?? '',
            'formatted_address' => $selected['formatted_address'] ?? '',
            'street_name' => $selected['street_name'] ?? '',
            'street_number' => $selected['street_number'] ?? '',
            'city' => $selected['city'] ?? '',
            'postal_code' => $selected['postal_code'] ?? '',
            'country' => $selected['country'] ?? 'Lietuva',
            'country_code' => $selected['country_code'] ?? 'LT',
            'latitude' => $selected['latitude'] ?? null,
            'longitude' => $selected['longitude'] ?? null,
            'confidence_score' => $confidence,
            'address_type' => $typeEnum->value,
            'address_type_label' => $typeEnum->label(),
            'badge_color' => $typeEnum->getBadgeColor(),
        ];

        $this->selectedAddress = $addressData;
        $this->resetSuggestions();
        // SVARBU: Išvalome query, kad po pasirinkimo liktų kortelė
        $this->query = '';

        $this->dispatchStateUpdate($this->selectedAddress);
    }

    public function handleKeydown(string $key): void
    {
        if (!$this->showSuggestions || empty($this->suggestions)) {
            return;
        }

        switch ($key) {
            case 'ArrowDown':
                $this->selectedIndex = min(
                    $this->selectedIndex + 1,
                    count($this->suggestions) - 1
                );
                break;
            case 'ArrowUp':
                $this->selectedIndex = max($this->selectedIndex - 1, -1);
                break;
            case 'Enter':
                if ($this->selectedIndex >= 0) {
                    $this->selectSuggestion($this->selectedIndex);
                }
                break;
            case 'Escape':
                $this->resetSuggestions();
                break;
        }
    }

    public function clearSelection(): void
    {
        $this->query = '';
        $this->selectedAddress = [];
        $this->resetSuggestions();
        $this->dispatchStateUpdate([]);
    }

    private function dispatchStateUpdate(array $data): void
    {
        // Siunčiame įvykį map'ui atnaujinti PIN poziciją
        $this->dispatch('addressSelected', data: $data);

        if ($this->statePath) {
            // Saugiausias būdas atnaujinti Filament lauko būseną per Livewire
            $this->dispatch('setState', statePath: $this->statePath, state: $data);
        }
    }

    private function resetSuggestions(): void
    {
        $this->suggestions = [];
        $this->showSuggestions = false;
        $this->selectedIndex = -1;
    }

    private function determineAddressTypeByConfidence(float $confidence): string
    {
        if ($confidence >= self::CONFIDENCE_VERIFIED) {
            return AddressTypeEnum::VERIFIED->value;
        }

        if ($confidence >= self::CONFIDENCE_LOW) {
            return AddressTypeEnum::LOW_CONFIDENCE->value;
        }

        return AddressTypeEnum::UNVERIFIED->value;
    }

    public function render()
    {
        // Naudojame Blade šabloną, kuris atitinka Livewire komponento pavadinimą
        return view('livewire.address-autocomplete');
    }
}
