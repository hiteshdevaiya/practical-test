<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;

/**
 * Renders the single-page autocomplete demo.
 *
 * The page is intentionally server-rendered with Blade and enhanced with a
 * small vanilla JavaScript module. The demo API key is a publishable key that
 * only grants the free search and billable select endpoints.
 */
final class SearchPageController extends Controller
{
    /**
     * Show the autocomplete demo.
     *
     * @return View
     */
    public function __invoke(): View
    {
        return view('search', [
            'apiKey' => (string) config('search.demo_api_key', ''),
            'minLength' => (int) config('search.min_token_length', 3),
            'defaultLimit' => (int) config('search.default_limit', 10),
            'demoConfig' => [
                'apiKey' => (string) config('search.demo_api_key', ''),
                'minLength' => (int) config('search.min_token_length', 3),
                'limit' => (int) config('search.default_limit', 10),
            ],
        ]);
    }
}
