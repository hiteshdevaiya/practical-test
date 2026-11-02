<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds a trigram index for short, word-aligned fragments.
 *
 * House numbers and two-character prefix fragments (for example "67" in
 * "67 Brid", or "av" in "court av") cannot use the plain content trigram index
 * because their search pattern contains fewer than three characters. Matching
 * them against " content" (so the fragment must start a word) keeps the
 * semantics correct, and indexing that padded, lower-cased expression lets
 * PostgreSQL answer the predicate from a bitmap index scan instead of
 * filtering tens of thousands of heap rows.
 */
return new class extends Migration
{
    /**
     * Create the padded expression trigram index.
     *
     * @return void
     */
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(
            "CREATE INDEX IF NOT EXISTS records_content_padded_trgm_idx
             ON records USING GIN ((' ' || lower(content)) gin_trgm_ops)
             WITH (fastupdate = off)"
        );
    }

    /**
     * Drop the padded expression trigram index.
     *
     * @return void
     */
    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS records_content_padded_trgm_idx');
    }
};
