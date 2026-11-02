<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Gives the read-only search role SSD-aware planner costs.
 *
 * PostgreSQL's default random_page_cost (4.0) assumes spinning disks, so the
 * planner underestimates the GIN trigram index and prefers a sequential scan
 * with a LIMIT. That is catastrophic for the autocomplete candidate query: a
 * moderately selective token (for example "gordon") made the planner scan
 * ~100k heap rows to collect the candidates, taking 100-250 ms on one million
 * rows. On an SSD the correct value is close to 1.1, after which the same
 * candidates come from a bitmap index scan in ~4 ms while genuinely broad
 * tokens ("street") still use a fast sequential scan.
 *
 * The setting is scoped to the read-only search role, so it applies to the
 * autocomplete path only.
 */
return new class extends Migration
{
    /**
     * Apply the SSD-oriented planner cost to the search role.
     *
     * @return void
     */
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        $role = (string) config('search.read_only_role.username', 'search_reader');

        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $role)) {
            return;
        }

        DB::statement('ALTER ROLE "'.Str::replace('"', '""', $role).'" SET random_page_cost = 1.1');
    }

    /**
     * Restore the PostgreSQL default for the search role.
     *
     * @return void
     */
    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        $role = (string) config('search.read_only_role.username', 'search_reader');

        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $role)) {
            return;
        }

        DB::statement('ALTER ROLE "'.Str::replace('"', '""', $role).'" RESET random_page_cost');
    }
};
