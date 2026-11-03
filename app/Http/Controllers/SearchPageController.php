<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Record;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

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
            'totalRecords' => $this->totalRecords(),
            'demoConfig' => [
                'apiKey' => (string) config('search.demo_api_key', ''),
                'minLength' => (int) config('search.min_token_length', 3),
                'limit' => (int) config('search.default_limit', 10),
            ],
        ]);
    }

    /**
     * Number of searchable records, cached briefly.
     *
     * An exact COUNT(*) is a full scan on a very large table, so this uses the
     * planner statistics (instant) and only counts exactly when the table has
     * never been analyzed.
     */
    private function totalRecords(): int
    {
        return (int) Cache::remember('demo:total-records', 600, function (): int {
            $estimate = (int) (DB::selectOne(
                "select reltuples::bigint as n from pg_class where oid = 'records'::regclass"
            )->n ?? 0);

            return $estimate > 0 ? $estimate : Record::query()->count();
        });
    }
}
