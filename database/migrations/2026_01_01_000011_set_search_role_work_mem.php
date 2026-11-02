<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Raises work_mem for the read-only search role.
 *
 * The server-wide setting still reflects an un-restarted default in some
 * environments. At ten million rows, a 4 MB work_mem makes GIN build lossy
 * bitmaps whose heap recheck dominates the query (measured p95 460 ms versus
 * 144 ms for the same prefix query at 16 MB). The role-level setting applies
 * to every new search connection without needing a server restart.
 */
return new class extends Migration
{
    /**
     * Apply the role-level setting.
     *
     * @return void
     */
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        $role = $this->role();

        if ($role === null) {
            return;
        }

        DB::statement("ALTER ROLE {$role} SET work_mem = '16MB'");
    }

    /**
     * Reset the role-level setting.
     *
     * @return void
     */
    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        $role = $this->role();

        if ($role === null) {
            return;
        }

        DB::statement("ALTER ROLE {$role} RESET work_mem");
    }

    /**
     * Quoted role name, or null when it is not configured or invalid.
     *
     * @return string|null
     */
    private function role(): ?string
    {
        $role = (string) config('search.read_only_role.username', 'search_reader');

        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $role)) {
            return null;
        }

        return '"'.Str::replace('"', '""', $role).'"';
    }
};
