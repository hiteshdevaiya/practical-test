<?php

declare(strict_types=1);

namespace App\Providers;

use App\Http\Middleware\AuthenticateApiClient;
use App\Services\Search\AutocompleteSearch;
use App\Services\Search\CachedAutocompleteSearch;
use App\Services\Search\PrefixIndexBuilder;
use App\Services\Search\QueryNormalizer;
use App\Services\Search\SearchMetrics;
use App\Services\Search\SearchService;
use App\Services\Search\TypoCorrector;
use App\Services\Usage\SearchCounter;
use App\Services\Usage\SelectionTokenService;
use App\Services\Usage\UsageRecorder;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register application services.
     *
     * @return void
     */
    public function register(): void
    {
        $this->app->bind(QueryNormalizer::class, static function (): QueryNormalizer {
            return new QueryNormalizer(
                (int) config('search.max_query_length'),
                (int) config('search.max_tokens'),
                (int) config('search.min_token_length'),
            );
        });

        $this->app->singleton(SearchMetrics::class);

        $this->app->bind(TypoCorrector::class, static function ($app): TypoCorrector {
            return new TypoCorrector(
                $app['db']->connection(config('search.connection')),
                (int) config('search.min_token_length'),
            );
        });

        $this->app->bind(PrefixIndexBuilder::class, static function ($app): PrefixIndexBuilder {
            return new PrefixIndexBuilder($app['db']->connection());
        });

        $this->app->bind(AutocompleteSearch::class, static function ($app): AutocompleteSearch {
            return new AutocompleteSearch(
                $app['db']->connection(config('search.connection')),
                $app->make(QueryNormalizer::class),
                $app->make(TypoCorrector::class),
                (int) config('search.candidate_cap'),
                (int) config('search.max_limit'),
                (int) config('search.min_token_length'),
                (int) config('search.filter_candidate_cap'),
            );
        });

        $this->app->bind(SearchService::class, static function ($app): SearchService {
            return $app->make(AutocompleteSearch::class);
        });

        $this->app->bind(CachedAutocompleteSearch::class, function ($app): CachedAutocompleteSearch {
            $store = config('search.cache.store');
            $cache = is_string($store) && $store !== ''
                ? $app['cache']->store($store)
                : $app->make(CacheRepository::class);

            return new CachedAutocompleteSearch(
                $app->make(SearchService::class),
                $cache,
                (bool) config('search.cache.enabled'),
                (int) config('search.cache.ttl'),
                (string) config('search.cache.prefix'),
                $app->make(QueryNormalizer::class),
            );
        });

        $this->app->bind(SelectionTokenService::class, static function (): SelectionTokenService {
            return new SelectionTokenService(
                (string) config('search.selection_token.secret', ''),
                (int) config('search.selection_token.ttl', 600),
            );
        });

        $this->app->bind(UsageRecorder::class, static function ($app): UsageRecorder {
            return new UsageRecorder($app['db']->connection());
        });

        $this->app->bind(SearchCounter::class, static function ($app): SearchCounter {
            return new SearchCounter($app->make(CacheRepository::class));
        });

        $this->app->bind(AuthenticateApiClient::class, static function ($app): AuthenticateApiClient {
            return new AuthenticateApiClient(
                $app->make(CacheRepository::class),
                (int) config('search.api_client_cache_ttl', 300),
            );
        });
    }

    /**
     * Bootstrap application services.
     *
     * @return void
     */
    public function boot(): void
    {
        $this->configureRateLimiting();
    }

    /**
     * Configure per-client, per-IP API rate limits.
     *
     * @return void
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('search', function (Request $request): Limit {
            return Limit::perMinute((int) config('search.rate_limits.search', 120))
                ->by($this->throttleKey($request));
        });

        RateLimiter::for('select', function (Request $request): Limit {
            return Limit::perMinute((int) config('search.rate_limits.select', 60))
                ->by($this->throttleKey($request));
        });
    }

    /**
     * Build a stable throttle key even before authentication middleware runs.
     *
     * @param Request $request Current request.
     * @return string
     */
    private function throttleKey(Request $request): string
    {
        $client = $request->attributes->get('api_client');

        if ($client !== null) {
            return 'client:'.$client->id.'|'.$request->ip();
        }

        $keyHash = hash('sha256', (string) $request->header('X-API-Key', ''));

        return 'key:'.$keyHash.'|'.$request->ip();
    }
}
