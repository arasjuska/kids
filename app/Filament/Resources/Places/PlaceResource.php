<?php

namespace App\Filament\Resources\Places;

use App\Filament\Resources\Places\Pages\CreatePlace;
use App\Filament\Resources\Places\Pages\EditPlace;
use App\Filament\Resources\Places\Pages\ListPlaces;
use App\Filament\Resources\Places\Schemas\PlaceForm;
use App\Filament\Resources\Places\Tables\PlacesTable;
use App\Models\Place;
use App\Services\AddressFormStateManager;
use BackedEnum;
use Filament\Resources\Resource;
// Importas pakeistas į teisingą 'Filament\Forms\Form'.
use Filament\Forms\Form; 
// Turi būti importuota, nes to reikalauja tėvinės klasės signatūra
use Filament\Schemas\Schema; 
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class PlaceResource extends Resource
{
    protected static ?string $model = Place::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    // PATAISYMAS: Pakeista signatūra į Schema, kad atitiktų Filament\Resources\Resource tėvinės klasės reikalavimą.
    public static function form(Schema $schema): Schema 
    {
        // 1. Inicijuojame AddressFormStateManager (per Laravel Service Container)
        $addressManager = app(AddressFormStateManager::class);

        // Naudojame $schema kintamąjį, kuris šiuo atveju veikia kaip Form objektas
        return $schema
            // 2. Iškviečiame make() metodą, kad gautume komponentų masyvą
            ->schema(PlaceForm::make($addressManager));
    }

    public static function table(Table $table): Table
    {
        return PlacesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPlaces::route('/'),
            'create' => CreatePlace::route('/create'),
            'edit' => EditPlace::route('/{record}/edit'),
        ];
    }
}
