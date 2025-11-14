<?php

namespace App\Filament\Resources\Places\Schemas;

use App\Filament\Forms\Components\AddressField;
use App\Services\AddressFormStateManager;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class PlaceForm
{
    public static function configure(Schema $schema, AddressFormStateManager $manager): Schema
    {
        return $schema->schema([
            Section::make('Bazinė informacija')
                ->schema([
                    TextInput::make('name')
                        ->label('Pavadinimas')
                        ->required()
                        ->maxLength(255),
                ]),

            Section::make('Lokacija')
                ->columnSpanFull()
                ->schema([
                    AddressField::make('address_state')
                        ->label('Adresas')
                        ->rules(['nullable'])
                        ->stateManager($manager)
                        ->countryCode('lt')
                        ->mapHeight(360),
                ]),
        ]);
    }
}
