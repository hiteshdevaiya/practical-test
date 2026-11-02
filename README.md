# Laravel Autocomplete Search with Selection-Based Billing

Autocomplete search over a `records` table (Laravel 13 + PostgreSQL).
Typing and searching are free. **Only selecting a suggestion is billed, exactly
once per selection.**

- Native PostgreSQL only (B-tree word tables + `pg_trgm`); no external engine.
- Search is read-only. Select uses a signed, expiring token + idempotent ledger.
- 10M rows, cache off: service p95 **28.7-31.7 ms** (target 50 ms). See
  [docs/benchmark-results.md](docs/benchmark-results.md) and
  [docs/implementation-checklist.md](docs/implementation-checklist.md).

---

## 1. Requirements

PHP 8.3+ (`pdo_pgsql`, `pgsql`), Composer 2, PostgreSQL 16+ with `pg_trgm`
on `127.0.0.1:5432`, Node 20.19+ or 22.12+.
Disk/RAM: 1M rows ~1 GB; 10M rows ~10 GB disk, 16 GB RAM.
Optional: Redis 7+, k6.

---

## 2. Setup

```bash
composer install && npm install
cp .env.example .env && php artisan key:generate

# Edit .env: DB_PASSWORD, SEARCH_DB_PASSWORD, SELECTION_TOKEN_SECRET
# secret: php -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;"

createdb -h 127.0.0.1 -U postgres laravel_search_demo
createdb -h 127.0.0.1 -U postgres laravel_search_demo_test
php artisan migrate

# Seed 1M rows (--fresh DELETES existing records and usage events)
php artisan records:seed --count=1000000 --batch=100000 --fresh --force --index-memory=1GB

php artisan api-client:create "Demo Client" --publish   # writes DEMO_API_KEY to .env
npm run build
php artisan serve --host=127.0.0.1 --port=8000
```

Open http://127.0.0.1:8000 (UI) and http://127.0.0.1:8000/docs (API docs).

### Two databases: which one is for what

| Database | Holds | Used by |
|---|---|---|
| `laravel_search_demo` (real) | Your seeded data (1M or 10M rows) | The running app, the demo, `search:benchmark`, `benchmarks/*` |
| `laravel_search_demo_test` | A few rows per test, reset for every test | `php artisan test` only (set in `phpunit.xml`) |

- The test database must exist, but it stays tiny. The tests never touch the
  real data, so it is safe to run them at any time.
- Tests prove the **logic** (billing exactly once, typos, timeouts, security).
  They cannot prove **speed or behaviour at 10M rows**; use the real database
  for that.
- To check the real database, run `php artisan search:benchmark --queries=2000`
  (speed) and use the demo page or the API with the demo key (behaviour).
  Searching never changes billing. Selecting a suggestion adds 1 usage to the
  demo client, so use a throwaway client (`php artisan api-client:create "Check"`)
  if you want to keep the demo counter at 0.
- For a presentation, testing on the real database is fine. The separate test
  database is only there so the automated tests stay fast and never change your
  data.

Verify:

```bash
php artisan test              # 115 passed, 434 assertions (test database)
php artisan usage:reconcile   # counters match the ledger (real database)
```

Full 10M dataset (long, multi-GB, get approval first):

```bash
php artisan records:seed --count=10000000 --batch=1000000 --fresh --force --index-memory=2GB
```

---

## 3. API

All routes need `X-API-Key`. JSON only.

| Method and path | Billed | Purpose |
|---|---|---|
| `GET /api/v1/search?q=&limit=` | No | `q` required, max 100 chars; `limit` 1-20 (default 10) |
| `POST /api/v1/search/select` | **+1** | Body `{"selection_token": "..."}` |
| `GET /api/v1/usage` | No | `{billable_usage_count, search_requests}` |

```bash
curl -H "X-API-Key: $DEMO_API_KEY" -H "Accept: application/json" \
  "http://127.0.0.1:8000/api/v1/search?q=londn%20road&limit=3"
```

```json
{ "query": "londn road", "corrected_query": "london road", "took_ms": 6.8,
  "data": [ { "id": 482193, "content": "25 London Road Leeds",
              "match": "corrected", "selection_token": "eyJ...Hk3" } ] }
```

- Under 3 usable characters returns `200` with `data: []` and **no DB query**.
- Select returns `{"data":{id,content},"usage":{"counted":true,"billable_usage_count":N}}`.
- Errors: `401` bad key, `403` another client's token, `404` record gone,
  `422` validation / bad signature / expired, `429` rate limit (120 searches,
  60 selects per minute).

---

## 4. Billing

| Situation | Counted? |
|---|---|
| Any search or typing | No |
| First valid selection of a token | **Yes, +1** |
| Same record chosen after a new search (new token) | **Yes, +1** |
| Same token replayed (double-click, retry) | No (`counted:false`) |
| Tampered, expired or another client's token | No (422 / 403) |

Token = `base64url(payload).base64url(HMAC-SHA256)`, valid 10 minutes.
`UNIQUE(selection_id)` + `ON CONFLICT DO NOTHING` + counter update run in one
transaction, so concurrent duplicates count once.

---

## 5. Search

```text
normalize -> word tables (adjacent pair, then rarest word) -> typo correction
          -> trigram GIN fallback (mid-word, stale data) -> rank
```

- `record_words` and `record_word_pairs` are **derived** from `records`.
- Every query has a candidate cap (200) and a 250 ms timeout; a timeout falls
  through to the next stage instead of failing.
- Code: `app/Services/Search/AutocompleteSearch.php`,
  `app/Services/Usage/UsageRecorder.php`. Tunables: `config/search.php`.

---

## 6. Other commands

```bash
php artisan search:build-prefix-index                 # rebuild word tables
php artisan api-client:revoke <id|name> [--reactivate]
php artisan search:benchmark --queries=2000           # p50/p95/p99, uncached
php benchmarks/explain.php                            # EXPLAIN of the real SQL
```

---

## 7. Keep in mind

- **Rebuild after changing records:** `php artisan search:build-prefix-index`,
  then `VACUUM (ANALYZE) record_words, record_word_pairs;`. Appended rows are
  still found (slower); updates and deletes are not detected. ~11 min at 10M.
- **Slowest shape:** house-number queries, p95 49.6-59.5 ms at 10M; worst single
  query 265 ms.
- **End-to-end HTTP at 10M is unverified.** App `Server-Timing` p95 is 35.5 ms;
  the k6/Octane runs used 1M rows and missed 50 ms on this Windows machine.
- **Storage:** word tables and indexes use ~8 GB at 10M, about 14x `records`.
- **Numbers are warm-cache;** the first run after a restart or rebuild is slower.
- **Data is synthetic** (545-word vocabulary). The demo key is public: do not
  ship it to production.
