<?php

namespace App\Filament\Resources\Places\Schemas;

use App\Filament\Forms\Components\AddressAutocomplete;
use App\Filament\Forms\Components\LeafletMap;
use App\Services\AddressFormStateManager; // Naudojame State Manager vėl
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;

class PlaceForm
{
    /**
     * @param AddressFormStateManager $addressManager
     * @return array
     */
    public static function make(AddressFormStateManager $addressManager): array
    {
        return [
            TextInput::make('name')
                ->label('Pavadinimas')
                ->columnSpanFull()
                ->required(),

            // ŽEMĖLAPIS: Judinant smeigtuką, iškviečia atvirkštinį geokodavimą
            LeafletMap::make('location')
                ->label('Adresas')
                ->defaultLocation(54.8985, 23.9036)
                ->zoom(17)
                ->columnSpanFull()
                ->live(debounce: 500)
                ->dehydrated(true)
                ->required()
                ->afterStateUpdated(function (array $state, Set $set) use ($addressManager) {
                    if (isset($state['latitude'], $state['longitude'])) {
                        // Atnaujiname koordinates ir inicijuojame Reverse Geocoding
                        $addressManager->updateCoordinates(
                            $state['latitude'],
                            $state['longitude'],
                            performReverseGeocode: true // Tai sukelia atvirkštinį geokodavimą
                        );

                        // KRITINIS PATAISYMAS: Atnaujiname 'full_address' su naujai rastu tekstu.
                        // Tai priverčia AddressAutocomplete lauką perpiešti save ir atidaryti dropdown.
                        $set('full_address', $addressManager->getAddressText());

                        // Atstatome trigger, kad užtikrinti reaktyvumą (jei reikia)
                        $set('map_updated_trigger', now()->timestamp);
                    }
                }),

            // Adreso paieškos laukas (AddressAutocomplete)
            AddressAutocomplete::make('full_address')
                ->label('Adreso paieška')
                ->columnSpanFull()
                ->searchable()
                ->suggestions($addressManager->getSuggestions()) // Naudojame manager pasiūlymus
                ->addressManager($addressManager)
                // Reikalinga, kad laukas būtų matomas, kai atsiranda pasiūlymų po smeigtuko pajudinimo
                ->visible(fn(Get $get) => empty($get('full_address')) || !empty($addressManager->getSuggestions()))
                ->dehydrated(false)
                ->live(debounce: 500)
                ->hint(fn(Get $get) => $get('map_updated_trigger') ? '' : ''), // Reaktyvumo gaidukas

            // Paslėptas trigger laukas
            TextInput::make('map_updated_trigger')
                ->hidden(),

            // Kiti laukai...
            TextInput::make('location_description')
                ->label('Vietos aprašymas')
                ->columnSpanFull()
                ->hint('Papildoma informacija apie vietą (pvz., pastato šone, miško aikštelė).'),

            // Paslėpti laukai, skirti būsenai sekti
            TextInput::make('coordinates_manually_changed')
                ->hidden(),
            TextInput::make('address_details_block')
                ->hidden(),
        ];
    }
}
