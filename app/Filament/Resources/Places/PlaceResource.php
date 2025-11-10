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
// PAŠALINTAS: Naudojant senesnę Filament versiją, 'Filament\Forms\Form' importas sukelia klaidą.
// use Filament\Forms\Form;
use Filament\Schemas\Schema; // Priverstinis importas, kad atitiktų tėvinės klasės signatūrą
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class PlaceResource extends Resource
{
    protected static ?string $model = Place::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    /**
     * Taisymas: Grąžinama signatūra į Schema $schema, kad atitiktų tėvinės klasės signatūrą
     * ir išvengtume 'Filament\Forms\Form' nepasiekiamumo klaidos.
     *
     * @param  Schema  $schema  (arba Form $form, bet dėl klaidos paliekame Schema)
     */
    public static function form(Schema $schema): Schema
    {
        // 1. Inicijuojame AddressFormStateManager (per Laravel Service Container)
        $addressManager = app(AddressFormStateManager::class);

        // 2. Taisymas: Kviečiamas esamas 'configure' metodas ir perduodamas $addressManager.
        return PlaceForm::configure($schema, $addressManager);
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
