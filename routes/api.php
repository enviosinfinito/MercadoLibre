<?php

use App\Http\Controllers\Api\V1\HealthController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('/health', HealthController::class)->name('api.v1.health');

    Route::middleware('auth:sanctum')->group(function () {
        // Sanctum-protected API stubs
        Route::get('/me', fn (\Illuminate\Http\Request $request) => $request->user());
    });
});
