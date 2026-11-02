<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Search\CachedAutocompleteSearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Verifies the result cache and its token isolation guarantees.
 */
final class SearchCacheTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Repeated identical searches are served from cache.
     *
     * @return void
     */
    public function test_repeated_search_is_cached(): void
    {
        config(['search.cache.enabled' => true]);
        Cache::flush();

        $this->seedRecords(['25 London Road Leeds', '91 London Road Manchester']);

        $service = app(CachedAutocompleteSearch::class);

        $first = $service->search('london', 5);
        $second = $service->search('london', 5);

        $this->assertFalse($first->cacheHit);
        $this->assertTrue($second->cacheHit);
        $this->assertSame(
            array_column($first->suggestionArrays(), 'id'),
            array_column($second->suggestionArrays(), 'id')
        );
    }

    /**
     * Only rows are cached; the service result itself never contains tokens.
     *
     * @return void
     */
    public function test_cache_payload_contains_no_selection_tokens(): void
    {
        config(['search.cache.enabled' => true]);
        Cache::flush();

        $this->seedRecords(['25 London Road Leeds']);
        $service = app(CachedAutocompleteSearch::class);
        $service->search('london', 5);

        $key = config('search.cache.prefix').':'.sha1('london|5');
        $payload = Cache::get($key);

        $this->assertIsArray($payload);
        $this->assertArrayHasKey('rows', $payload);
        $this->assertStringNotContainsString('selection_token', json_encode($payload));
    }

    /**
     * Cache entries are cleared independently and never affect billing.
     *
     * @return void
     */
    public function test_cache_does_not_create_usage_events(): void
    {
        config(['search.cache.enabled' => true]);
        Cache::flush();

        $this->seedRecords(['25 London Road Leeds']);
        $service = app(CachedAutocompleteSearch::class);

        $service->search('london', 5);
        $service->search('london', 5);

        $this->assertSame(0, DB::table('api_usage_events')->count());
    }
}
