import http from 'k6/http';
import { check } from 'k6';
import { SharedArray } from 'k6/data';

/**
 * k6 load test for the non-billable autocomplete endpoint.
 *
 * The corpus is produced by:
 *   php artisan search:benchmark --queries=2000 --export=benchmarks/queries.json
 *
 * Run it with the result cache disabled to prove the database path alone can
 * satisfy the latency target:
 *   SEARCH_CACHE_ENABLED=false php artisan serve
 *   k6 run -e BASE_URL=http://127.0.0.1:8000 -e API_KEY=<key> benchmarks/k6-search.js
 *
 * Override the load profile with -e VUS=1|20|50 and -e DURATION=60s.
 */

const queries = new SharedArray('queries', () => JSON.parse(open('./queries.json')));

const vus = Number(__ENV.VUS || 20);
const duration = __ENV.DURATION || '60s';

export const options = {
    scenarios: {
        autocomplete: {
            executor: 'constant-vus',
            vus,
            duration,
        },
    },
    thresholds: {
        http_req_duration: ['p(95)<50', 'p(99)<100'],
        http_req_failed: ['rate<0.01'],
    },
};

/**
 * Issue one randomized autocomplete request and assert a usable response.
 *
 * @returns {void}
 */
export default function () {
    const query = queries[Math.floor(Math.random() * queries.length)];
    const url = `${__ENV.BASE_URL}/api/v1/search?q=${encodeURIComponent(query)}&limit=10`;

    const response = http.get(url, {
        headers: {
            'X-API-Key': __ENV.API_KEY,
            Accept: 'application/json',
        },
    });

    check(response, {
        'status is 200': (res) => res.status === 200,
        'response is JSON': (res) => (res.headers['Content-Type'] || '').includes('application/json'),
    });
}
