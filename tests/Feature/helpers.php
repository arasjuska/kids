<?php

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

        return $manager->getStateSnapshot();
    }
}
