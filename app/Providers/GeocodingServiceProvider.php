<?php

namespace App\Providers;

use App\Contracts\GeocodingServiceInterface;
use App\Services\GeocodingService;
use Illuminate\Support\ServiceProvider;

class GeocodingServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->singleton(GeocodingServiceInterface::class, function () {
            return new GeocodingService();
        });

        $this->app->alias(GeocodingServiceInterface::class, 'geocoding');
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }
}
