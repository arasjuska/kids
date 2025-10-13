<?php

namespace App\Filament\Forms\Components;

use Closure;
use Filament\Forms\Components\TextInput;

/**
 * Custom Filament Form Component for address autocomplete functionality.
 * This component extends TextInput and adds custom logic for suggestions and state management.
 */
class AddressAutocomplete extends TextInput
{
    /** @var array|Closure|null */
    protected array | Closure | null $suggestions = [];
    
    /** @var mixed */
    protected mixed $addressManager;

    /**
     * Pataisymas: Pridėtas tuščias searchable metodas, kad išvengti klaidos.
     * Šis metodas reikalingas tik tam, kad PlaceForm.php galėtų jį naudoti be klaidų.
     * @param bool $condition
     * @return $this
     */
    public function searchable(bool $condition = true): static
    {
        // Šis komponentas jau yra 'searchable' pagal savo paskirtį (TextInput),
        // bet mes apibrėžiame šią funkciją, kad būtų išvengta "Method does not exist" klaidos.
        return $this;
    }

    /**
     * Nustato adreso pasiūlymus iš AddressFormStateManager.
     * @param array|Closure|null $suggestions
     * @return $this
     */
    public function suggestions(array | Closure | null $suggestions): static
    {
        $this->suggestions = $suggestions;

        return $this;
    }

    /**
     * Grąžina adreso pasiūlymus.
     * @return array|null
     */
    public function getSuggestions(): ?array
    {
        return $this->evaluate($this->suggestions);
    }

    /**
     * Priskiria AddressFormStateManager klasę.
     * @param mixed $manager
     * @return $this
     */
    public function addressManager(mixed $manager): static
    {
        $this->addressManager = $manager;

        return $this;
    }

    /**
     * Grąžina AddressFormStateManager instanciją.
     * @return mixed
     */
    public function getAddressManager(): mixed
    {
        return $this->addressManager;
    }
    
    // Čia turėtų būti ir pagrindinė Livewire logika, kuri apdoroja input'ą ir rodo dropdown'ą.
    // Dėl supaprastinimo paliekame tai kaip TextInput, bet pridedame custom savybes.
}
