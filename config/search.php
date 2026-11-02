<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Search Connection
    |--------------------------------------------------------------------------
    |
    | All read-only autocomplete queries run on this connection. The role is
    | configured with default_transaction_read_only = on, so the search path
    | cannot write to the database even if application code is compromised.
    |
    */

    'connection' => env('SEARCH_DB_CONNECTION', 'pgsql_search'),

    /*
    |--------------------------------------------------------------------------
    | Query Normalization
    |--------------------------------------------------------------------------
    */

    'min_token_length' => (int) env('SEARCH_MIN_TOKEN_LENGTH', 3),
    'max_query_length' => (int) env('SEARCH_MAX_QUERY_LENGTH', 100),
    'max_tokens' => (int) env('SEARCH_MAX_TOKENS', 6),

    /*
    |--------------------------------------------------------------------------
    | Result Limits and Candidate Cap
    |--------------------------------------------------------------------------
    |
    | The candidate cap bounds the number of rows that ranking may touch,
    | keeping latency predictable on very broad queries.
    |
    */

    'default_limit' => (int) env('SEARCH_DEFAULT_LIMIT', 10),
    'max_limit' => (int) env('SEARCH_MAX_LIMIT', 20),
    'candidate_cap' => (int) env('SEARCH_CANDIDATE_CAP', 200),

    // Candidate window for the B-tree word-table plans (adjacent-pair lookup
    // and rarest-word range scan): rows read before ranking.
    'filter_candidate_cap' => (int) env('SEARCH_FILTER_CANDIDATE_CAP', 200),

    /*
    |--------------------------------------------------------------------------
    | Result Cache
    |--------------------------------------------------------------------------
    |
    | Only search rows are cached, never selection tokens. The cache store is
    | resolved through Laravel's cache abstraction, so Redis can be used when
    | available without changing application code.
    |
    */

    'cache' => [
        'enabled' => filter_var(env('SEARCH_CACHE_ENABLED', true), FILTER_VALIDATE_BOOL),
        'store' => env('SEARCH_CACHE_STORE') ?: null,
        'ttl' => (int) env('SEARCH_CACHE_TTL', 600),
        'prefix' => (string) env('SEARCH_CACHE_PREFIX', 'ac:v1'),
    ],

    /*
    |--------------------------------------------------------------------------
    | API Client Cache
    |--------------------------------------------------------------------------
    |
    | The resolved client is cached to avoid a database lookup on every
    | keystroke. Revocation commands clear the cache immediately.
    |
    */

    'api_client_cache_ttl' => (int) env('API_CLIENT_CACHE_TTL', 300),

    /*
    |--------------------------------------------------------------------------
    | Demo Frontend
    |--------------------------------------------------------------------------
    |
    | The publishable key embedded in the Blade page. It can only call the
    | search, select and usage endpoints, and is rate limited like any key.
    |
    */

    'demo_api_key' => (string) env('DEMO_API_KEY', ''),

    /*
    |--------------------------------------------------------------------------
    | Read-only Search Role
    |--------------------------------------------------------------------------
    |
    | Used by the role migration to create the restricted PostgreSQL login
    | that all search queries use. Never commit the real password.
    |
    */

    'read_only_role' => [
        'username' => (string) env('SEARCH_DB_USERNAME', 'search_reader'),
        'password' => (string) env('SEARCH_DB_PASSWORD', ''),
        'statement_timeout' => (string) env('SEARCH_DB_STATEMENT_TIMEOUT', '250ms'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Selection Tokens
    |--------------------------------------------------------------------------
    */

    'selection_token' => [
        'secret' => env('SELECTION_TOKEN_SECRET'),
        'ttl' => (int) env('SELECTION_TOKEN_TTL', 600),
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate Limits (requests per minute)
    |--------------------------------------------------------------------------
    */

    'rate_limits' => [
        'search' => (int) env('SEARCH_RATE_LIMIT', 120),
        'select' => (int) env('SELECT_RATE_LIMIT', 60),
    ],

];
