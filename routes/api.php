<?php

use App\Http\Controllers\Api\RouteController;
use App\Http\Controllers\Api\StopController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('/stops', [StopController::class, 'index'])->name('api.stops.index');
    Route::get('/stops/nearby', [StopController::class, 'nearby'])->name('api.stops.nearby');
    Route::get('/stops/{stop}', [StopController::class, 'show'])->name('api.stops.show');

    Route::get('/routes', [RouteController::class, 'index'])->name('api.routes.index');
    Route::get('/routes/{id}/stops', [RouteController::class, 'stops'])->name('api.routes.stops');
});
