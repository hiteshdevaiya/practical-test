<?php

declare(strict_types=1);

use App\Http\Controllers\ApiDocsController;
use App\Http\Controllers\SearchPageController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| The demo UI is the only web route. All search and billing operations are
| exposed through the stateless JSON API under /api/v1.
|
*/

Route::get('/', SearchPageController::class)->name('search.demo');

Route::get('docs', [ApiDocsController::class, 'ui'])->name('docs');
Route::get('docs/openapi.json', [ApiDocsController::class, 'spec'])->name('docs.spec');
