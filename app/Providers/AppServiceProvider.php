<?php

namespace App\Providers;

use App\Contracts\GeocodingServiceInterface;
use App\Services\GeocodingService;
use App\Support\AddressNormalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(AddressNormalizer::class);
        $this->app->bind(GeocodingServiceInterface::class, GeocodingService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if (app()->environment('local', 'development')) {
            DB::listen(function ($query) {
                if ($query->time > 100) {
                    logger()->channel('performance')->debug($query->sql, $query->bindings);
                }
            });
        }

        View::share(
            'shouldIncludeViteAssets',
            ! app()->runningUnitTests() &&
            (File::exists(public_path('build/manifest.json')) || File::exists(public_path('hot')))
        );
    }
}
