<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\ApiClient;
use Closure;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates API requests using the X-API-Key header.
 *
 * Keys are hashed with SHA-256 before lookup, and the resolved client is
 * cached briefly to avoid a database query on every autocomplete keystroke.
 * Inactive keys are never cached, and api-client:revoke clears the cache so
 * deactivation takes effect immediately.
 */
final class AuthenticateApiClient
{
    /**
     * Cache key prefix for resolved API clients.
     */
    public const CACHE_PREFIX = 'api-client:';

    /**
     * @param CacheRepository $cache Cache store.
     * @param int $cacheTtl Seconds a resolved client may be cached.
     */
    public function __construct(
        private readonly CacheRepository $cache,
        private readonly int $cacheTtl = 300,
    ) {}

    /**
     * Authenticate the request or return a JSON 401 response.
     *
     * @param Request $request Incoming request.
     * @param Closure $next Next middleware/handler.
     * @return Response
     */
    public function handle(Request $request, Closure $next): Response
    {
        $key = trim((string) $request->header('X-API-Key', ''));

        if ($key === '') {
            return $this->unauthorized('Missing API key.');
        }

        $hash = hash('sha256', $key);
        $cacheKey = self::CACHE_PREFIX.$hash;
        $attributes = null;

        try {
            $attributes = $this->cache->get($cacheKey);
        } catch (\Throwable $exception) {
            report($exception);
        }

        if (! is_array($attributes)) {
            $client = ApiClient::query()
                ->active()
                ->where('api_key_hash', $hash)
                ->first();

            if ($client === null) {
                return $this->unauthorized('Invalid API key.');
            }

            $attributes = $client->only(['id', 'name', 'billable_usage_count', 'is_active']);

            try {
                $this->cache->put($cacheKey, $attributes, $this->cacheTtl);
            } catch (\Throwable $exception) {
                report($exception);
            }
        }

        if (($attributes['is_active'] ?? false) !== true) {
            return $this->unauthorized('Inactive API key.');
        }

        $request->attributes->set('api_client', (new ApiClient)->newFromBuilder($attributes));

        return $next($request);
    }

    /**
     * Build a JSON 401 response.
     *
     * @param string $message Safe error message.
     * @return Response
     */
    private function unauthorized(string $message): Response
    {
        return response()->json(['message' => $message], 401);
    }
}
