<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Search\SearchMetrics;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Adds a Server-Timing header so latency is observable from the browser.
 *
 * The search controller records database and cache timings in a per-request
 * collector; this middleware combines them with the total application
 * duration. The collector is reset before the request is handled so a
 * long-lived worker cannot leak metrics between requests.
 */
final class AddServerTiming
{
    /**
     * @param SearchMetrics $metrics Per-request timing collector.
     */
    public function __construct(
        private readonly SearchMetrics $metrics,
    ) {}

    /**
     * Attach timing metrics to the response.
     *
     * @param Request $request Incoming request.
     * @param Closure $next Next middleware/handler.
     * @return Response
     */
    public function handle(Request $request, Closure $next): Response
    {
        $this->metrics->reset();
        $startedAt = hrtime(true);
        $response = $next($request);
        $appMs = (hrtime(true) - $startedAt) / 1_000_000;

        $segments = [sprintf('app;dur=%.2f', $appMs)];

        if ($this->metrics->hasMetrics()) {
            $segments[] = sprintf('db;dur=%.2f', (float) $this->metrics->dbMs());
            $segments[] = sprintf('cache;desc="%s"', (string) $this->metrics->cache());
        }

        $response->headers->set('Server-Timing', implode(', ', $segments));

        return $response;
    }
}
