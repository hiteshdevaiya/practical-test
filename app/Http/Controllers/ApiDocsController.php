<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;

/**
 * Serves the Swagger UI page and the OpenAPI 3.0 specification.
 */
final class ApiDocsController extends Controller
{
    /**
     * Render the Swagger UI page.
     *
     * @return View
     */
    public function ui(): View
    {
        return view('docs', [
            'apiKey' => (string) config('search.demo_api_key', ''),
        ]);
    }

    /**
     * Return the OpenAPI specification as JSON.
     *
     * @return JsonResponse
     */
    public function spec(): JsonResponse
    {
        $error = fn (string $description): array => [
            'description' => $description,
            'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]],
        ];

        $ok = fn (string $description, string $schema): array => [
            'description' => $description,
            'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/'.$schema]]],
        ];

        $demoKey = (string) config('search.demo_api_key', '');
        $maxQuery = (int) config('search.max_query_length', 100);

        $spec = [
            'openapi' => '3.0.3',
            'info' => [
                'title' => 'Autocomplete Search API',
                'version' => '1.0.0',
                'description' => "Autocomplete search over PostgreSQL (pg_trgm).\n\n"
                    ."- **Searching is free.** `GET /search` never changes the billable counter.\n"
                    ."- **Selecting is billable.** `POST /search/select` records one billable usage, "
                    ."idempotent per `selection_token`.\n\n"
                    .'Click **Authorize** and enter your `X-API-Key` before trying requests.'
                    .($demoKey !== '' ? "\n\n**Demo API key:** `{$demoKey}`" : ''),
            ],
            'servers' => [['url' => url('/api/v1'), 'description' => 'Current host']],
            'tags' => [
                ['name' => 'Search', 'description' => 'Free autocomplete suggestions'],
                ['name' => 'Selection', 'description' => 'Billable selection of a suggestion'],
                ['name' => 'Usage', 'description' => 'Usage counters'],
            ],
            'security' => [['ApiKeyAuth' => []]],
            'paths' => [
                '/search' => ['get' => [
                    'tags' => ['Search'],
                    'summary' => 'Search suggestions (free)',
                    'description' => 'Supports partial, any-order tokens, typo correction and house-number '
                        .'filtering. Each suggestion carries a signed `selection_token`.',
                    'parameters' => [
                        [
                            'name' => 'q', 'in' => 'query', 'required' => true,
                            'description' => "Search text (max {$maxQuery} chars).",
                            'schema' => ['type' => 'string', 'maxLength' => $maxQuery],
                            'example' => 'london road',
                        ],
                        [
                            'name' => 'limit', 'in' => 'query', 'required' => false,
                            'description' => 'Maximum suggestions to return.',
                            'schema' => [
                                'type' => 'integer',
                                'minimum' => 1,
                                'maximum' => (int) config('search.max_limit', 20),
                                'default' => (int) config('search.default_limit', 10),
                            ],
                        ],
                    ],
                    'responses' => [
                        '200' => $ok('Ranked suggestions', 'SearchResponse'),
                        '401' => $error('Missing or invalid API key'),
                        '422' => $error('Validation failed'),
                        '429' => $error('Rate limit exceeded'),
                    ],
                ]],
                '/search/select' => ['post' => [
                    'tags' => ['Selection'],
                    'summary' => 'Select a suggestion (billable)',
                    'description' => 'Verifies the token, returns the record and records one billable usage. '
                        .'Repeating the same token does not bill twice (`counted: false`).',
                    'requestBody' => [
                        'required' => true,
                        'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/SelectRequest']]],
                    ],
                    'responses' => [
                        '200' => $ok('Record returned and usage recorded', 'SelectResponse'),
                        '401' => $error('Missing or invalid API key'),
                        '403' => $error('Token belongs to another client'),
                        '404' => $error('Record no longer exists'),
                        '422' => $error('Invalid or expired selection token'),
                        '429' => $error('Rate limit exceeded'),
                    ],
                ]],
                '/usage' => ['get' => [
                    'tags' => ['Usage'],
                    'summary' => 'Current usage totals',
                    'responses' => [
                        '200' => $ok('Usage counters', 'UsageResponse'),
                        '401' => $error('Missing or invalid API key'),
                    ],
                ]],
            ],
            'components' => [
                'securitySchemes' => [
                    'ApiKeyAuth' => ['type' => 'apiKey', 'in' => 'header', 'name' => 'X-API-Key'],
                ],
                'schemas' => [
                    'Error' => [
                        'type' => 'object',
                        'properties' => ['message' => ['type' => 'string', 'example' => 'Unauthenticated.']],
                    ],
                    'Suggestion' => [
                        'type' => 'object',
                        'additionalProperties' => true,
                        'properties' => [
                            'id' => ['type' => 'integer', 'example' => 1234],
                            'content' => ['type' => 'string', 'example' => '25 London Road, Manchester'],
                            'selection_token' => [
                                'type' => 'string',
                                'description' => 'Signed, client-bound token used for billing.',
                            ],
                        ],
                    ],
                    'SearchResponse' => [
                        'type' => 'object',
                        'properties' => [
                            'query' => ['type' => 'string', 'example' => 'londn road'],
                            'corrected_query' => ['type' => 'string', 'nullable' => true, 'example' => 'london road'],
                            'took_ms' => ['type' => 'number', 'example' => 4.21],
                            'data' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Suggestion']],
                        ],
                    ],
                    'SelectRequest' => [
                        'type' => 'object',
                        'required' => ['selection_token'],
                        'properties' => ['selection_token' => ['type' => 'string', 'maxLength' => 4096]],
                    ],
                    'SelectResponse' => [
                        'type' => 'object',
                        'properties' => [
                            'data' => [
                                'type' => 'object',
                                'properties' => [
                                    'id' => ['type' => 'integer'],
                                    'content' => ['type' => 'string'],
                                ],
                            ],
                            'usage' => [
                                'type' => 'object',
                                'properties' => [
                                    'counted' => [
                                        'type' => 'boolean',
                                        'description' => 'False when this token was already billed.',
                                    ],
                                    'billable_usage_count' => ['type' => 'integer'],
                                ],
                            ],
                        ],
                    ],
                    'UsageResponse' => [
                        'type' => 'object',
                        'properties' => [
                            'billable_usage_count' => ['type' => 'integer'],
                            'search_requests' => ['type' => 'integer'],
                        ],
                    ],
                ],
            ],
        ];

        return response()->json($spec);
    }
}
