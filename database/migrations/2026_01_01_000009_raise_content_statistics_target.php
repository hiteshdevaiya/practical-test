<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Raises the statistics target for the searched column.
 *
 * PostgreSQL's default target (100) produces very coarse trigram selectivity
 * estimates: on one million rows a token matching 0.6% of the table was
 * estimated at 3%, so the planner chose a sequential scan with a LIMIT and
 * spent ~70 ms collecting candidates. With a target of 1000 the estimate
 * tracks the real distribution, the selective token uses the GIN trigram index
 * (~4 ms), and genuinely broad tokens still use the faster sequential scan.
 */
return new class extends Migration
{
    /**
     * Increase the statistics target and refresh the estimates.
     *
     * @return void
     */
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE records ALTER COLUMN content SET STATISTICS 1000');

        // New statistics are only used after an ANALYZE.
        DB::connection()->statement('ANALYZE records');
    }

    /**
     * Restore the PostgreSQL default statistics target.
     *
     * @return void
     */
    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE records ALTER COLUMN content SET STATISTICS -1');
        DB::connection()->statement('ANALYZE records');
    }
};
