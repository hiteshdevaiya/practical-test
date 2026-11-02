<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Enables the PostgreSQL trigram extension used for indexed substring and
 * similarity searches on the records table.
 */
return new class extends Migration
{
    /**
     * Enable pg_trgm.
     *
     * @return void
     */
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new RuntimeException('This application requires PostgreSQL with pg_trgm.');
        }

        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
    }

    /**
     * Remove the extension when rolling back a fresh installation.
     *
     * @return void
     */
    public function down(): void
    {
        DB::statement('DROP EXTENSION IF EXISTS pg_trgm');
    }
};
