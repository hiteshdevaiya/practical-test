<?php

declare(strict_types=1);

use App\Http\Controllers\Api\SearchController;
use App\Http\Controllers\Api\SelectionController;
use App\Http\Controllers\Api\UsageController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| All endpoints require the X-API-Key header. Only the select endpoint is
| billable; search and usage endpoints never change the billable counter.
|
*/

Route::prefix('v1')
    ->middleware(['api.client', 'server.timing'])
    ->group(function (): void {
        Route::get('search', SearchController::class)
            ->middleware('throttle:search');

        Route::post('search/select', SelectionController::class)
            ->middleware('throttle:select');

        Route::get('usage', UsageController::class);
    });
