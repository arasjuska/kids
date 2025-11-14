<?php

declare(strict_types=1);

namespace App\Filament\Resources\Places\Pages;

use App\Filament\Resources\Places\Pages\Concerns\HandlesAddressSnapshots;
use App\Filament\Resources\Places\PlaceResource;
use App\Http\Requests\PlaceRequest;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreatePlace extends CreateRecord
{
    use HandlesAddressSnapshots;
    protected static string $resource = PlaceResource::class;

    // Kadangi dabar naudojame AddressFormStateManager, šis kintamasis nebereikalingas
    // (bet palieku jį užkomentuotą, jei būtų kitų formos laukų, kurie jį naudoja).
    // public ?string $selected_address_data = null;

    /**
     * Nustato pradinius formos duomenis (kai puslapis užkraunamas).
     * Ši logika dabar perduodama AddressFormStateManager klasei.
     */
    protected function getInitialFormdata(): array
    {
        // Don’t prime address state on create – avoid saving defaults.
        // If debug flag is present on initial GET (?dd_address=1), persist it into state,
        // so it survives Livewire updates.
        $control = [];
        if (config('app.debug') && request()->boolean('dd_address')) {
            $control['__dd'] = true;
        }

        return [
            'name' => null,
            'address_state' => [
                'control' => $control,
            ],
        ];
    }

    /**
     * Mutuoja duomenis prieš įrašymą.
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (app()->environment(['local', 'testing', 'development'])) {
            logger()->info('addr.request.raw', [
                'all' => request()->all(),
                'query_hex' => collect(request()->query())
                    ->map(fn ($v) => is_string($v) ? bin2hex($v) : $v)
                    ->all(),
                'input_hex' => collect(request()->input())
                    ->map(fn ($v) => is_string($v) ? bin2hex($v) : $v)
                    ->all(),
            ]);
        }

        $addressSnapshot = $data['address_state'] ?? [];
        if (config('app.debug')) {
            \Log::debug('CreatePlace: snapshot received', $addressSnapshot);
        }
        unset($data['address_state']);

        $addressPayload = $this->resolveAddressPayloadFromSnapshot($addressSnapshot);

        $addressRecord = $this->persistAddressPayload($addressPayload);

        $data['address_id'] = $addressRecord->id;

        return PlaceRequest::normalizePayload($data);
    }

    protected function afterCreate(): void
    {
        $this->record->loadMissing('address');

        $formatted = optional($this->record->address)->formatted_address;

        if (! $formatted) {
            return;
        }

        Notification::make()
            ->title('Adresas išsaugotas')
            ->body("Adresą galite nukopijuoti:\n{$formatted}")
            ->success()
            ->seconds(10)
            ->send();
    }

}
