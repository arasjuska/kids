<?php

use App\Console\Commands\GeocodeLookupCommand;
use App\Http\Middleware\SanitizeUtf8;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands([
        GeocodeLookupCommand::class,
    ])
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->append(SanitizeUtf8::class);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
