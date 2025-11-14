<?php

use App\Enums\InputModeEnum;
use App\Services\AddressFormStateManager;

if (! function_exists('manualAddressState')) {
    /**
     * @param  array<string, mixed>  $fields
     */
    function manualAddressState(array $fields, ?callable $tap = null): array
    {
        /** @var AddressFormStateManager $manager */
        $manager = app(AddressFormStateManager::class);

        $manager->switchToManualMode();

        foreach ($fields as $key => $value) {
            $manager->updateManualField($key, $value);
        }

        if ($tap) {
            $tap($manager);
        }

        $manager->validateAndPrepareForSubmission();
        $manager->markConfirmed(InputModeEnum::MANUAL);

        $snapshot = $manager->getStateSnapshot();
        data_set($snapshot, 'ui.editing', false);

        return $snapshot;
    }
}
