<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Creates the B-tree word index used by the autocomplete candidate path.
 *
 * A GIN index has to materialise whole posting lists: for common words such
 * as "road" or "street" that is hundreds of thousands of entries at ten
 * million rows, and prefix terms add a partial-match scan on top. This
 * ordinary table turns a query into index lookups that stop after the
 * candidate cap:
 *
 *   record_words       (word, record_id)    one row per word in a record
 *   record_word_pairs  (w1, w2, record_id)  one row per adjacent word pair
 *
 * Typing is progressive, so most multi-token queries are adjacent words
 * ("church lane bri"). An adjacent pair is selective on its own (hundreds of
 * rows where either word alone matches tens of thousands), so one pair lookup
 * answers it. Everything else (a single token, or tokens that are not
 * neighbours) uses record_words: the primary key drives the rarest token's
 * range scan and the secondary (record_id, word) index answers "does this
 * record also contain that token" for every other token.
 *
 * Storing the complete word (not a fixed three-character prefix) keeps long
 * tokens selective: "catherin" only matches "catherine", not "cathcart".
 * Partial input is answered with a B-tree prefix range ("word >= ? AND
 * word < ?"), so one index scan serves both complete and partial words.
 *
 * The table is rebuilt from records by
 * `php artisan search:build-prefix-index` (and by the seeder). It is derived
 * data: records stays the source of truth, and the search service falls back
 * to the GIN plans when it is empty or stale.
 */
return new class extends Migration
{
    /**
     * Create the word index table.
     *
     * @return void
     */
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(
            'CREATE TABLE IF NOT EXISTS record_words (
                record_id BIGINT NOT NULL,
                word TEXT NOT NULL,
                PRIMARY KEY (word, record_id)
            ) WITH (fillfactor = 100)'
        );

        DB::statement(
            'CREATE INDEX IF NOT EXISTS record_words_record_idx
             ON record_words (record_id, word) WITH (fillfactor = 100)'
        );

        DB::statement(
            'CREATE TABLE IF NOT EXISTS record_word_pairs (
                record_id BIGINT NOT NULL,
                w1 TEXT NOT NULL,
                w2 TEXT NOT NULL,
                PRIMARY KEY (w1, w2, record_id)
            ) WITH (fillfactor = 100)'
        );

        $this->grantReadAccess();
    }

    /**
     * Let the read-only search role read the derived index table.
     *
     * @return void
     */
    private function grantReadAccess(): void
    {
        $role = (string) config('search.read_only_role.username', 'search_reader');

        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $role)) {
            return;
        }

        if (! DB::selectOne('SELECT 1 FROM pg_roles WHERE rolname = ?', [$role])) {
            return;
        }

        $quotedRole = '"'.str_replace('"', '""', $role).'"';

        DB::statement("GRANT SELECT ON TABLE record_words, record_word_pairs TO {$quotedRole}");
    }

    /**
     * Drop the word index table.
     *
     * @return void
     */
    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP TABLE IF EXISTS record_words');
        DB::statement('DROP TABLE IF EXISTS record_word_pairs');
    }
};
