<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creates the read-only PostgreSQL role used by the autocomplete search path.
 *
 * The role can only SELECT from records and search_vocabulary, has an
 * aggressive statement timeout, and runs with default_transaction_read_only
 * enabled. A search request therefore cannot write data or change billing.
 */
return new class extends Migration
{
    /**
     * Create or update the role and its grants.
     *
     * @return void
     */
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        $role = (string) config('search.read_only_role.username', 'search_reader');
        $password = (string) config('search.read_only_role.password', '');
        $statementTimeout = (string) config('search.read_only_role.statement_timeout', '250ms');

        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $role)) {
            throw new RuntimeException('SEARCH_DB_USERNAME must be a valid PostgreSQL role name.');
        }

        if ($password === '') {
            throw new RuntimeException(
                'SEARCH_DB_PASSWORD must be configured before migrating the read-only search role.'
            );
        }

        $quotedRole = $this->quoteIdentifier($role);
        $quotedPassword = DB::connection()->getPdo()->quote($password);
        $database = $this->quoteIdentifier(DB::connection()->getDatabaseName());

        $exists = (bool) DB::selectOne('SELECT 1 FROM pg_roles WHERE rolname = ?', [$role]);

        if ($exists) {
            DB::statement("ALTER ROLE {$quotedRole} WITH LOGIN PASSWORD {$quotedPassword}");
        } else {
            DB::statement("CREATE ROLE {$quotedRole} LOGIN PASSWORD {$quotedPassword}");
        }

        DB::statement("GRANT CONNECT ON DATABASE {$database} TO {$quotedRole}");
        DB::statement("GRANT USAGE ON SCHEMA public TO {$quotedRole}");
        DB::statement("GRANT SELECT ON TABLE records, search_vocabulary TO {$quotedRole}");

        DB::statement("ALTER ROLE {$quotedRole} SET default_transaction_read_only = on");
        DB::statement("ALTER ROLE {$quotedRole} SET statement_timeout = '".$this->escapeLiteral($statementTimeout)."'");
        DB::statement("ALTER ROLE {$quotedRole} SET jit = off");
    }

    /**
     * Revoke grants and remove the role.
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

        $quotedRole = $this->quoteIdentifier($role);
        $database = $this->quoteIdentifier(DB::connection()->getDatabaseName());

        DB::statement("REVOKE ALL ON TABLE records, search_vocabulary FROM {$quotedRole}");
        DB::statement("REVOKE ALL ON SCHEMA public FROM {$quotedRole}");
        DB::statement("REVOKE CONNECT ON DATABASE {$database} FROM {$quotedRole}");

        $exists = (bool) DB::selectOne('SELECT 1 FROM pg_roles WHERE rolname = ?', [$role]);

        if ($exists) {
            DB::statement("DROP ROLE {$quotedRole}");
        }
    }

    /**
     * Quote a PostgreSQL identifier safely.
     *
     * @param string $identifier Raw identifier.
     * @return string Quoted identifier.
     */
    private function quoteIdentifier(string $identifier): string
    {
        return '"'.Str::replace('"', '""', $identifier).'"';
    }

    /**
     * Escape a value that is embedded in a SQL literal (for example a
     * PostgreSQL setting value that cannot be bound as a parameter).
     *
     * @param string $value Raw literal value.
     * @return string Escaped value.
     */
    private function escapeLiteral(string $value): string
    {
        return Str::replace("'", "''", $value);
    }
};
