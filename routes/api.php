<?php

use App\Http\Controllers\Api\MapClustersController;
use Illuminate\Support\Facades\Route;

Route::middleware('api')->group(function (): void {
    Route::get('/map/clusters', [MapClustersController::class, 'index']);
});
