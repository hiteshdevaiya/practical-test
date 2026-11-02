<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the append-only usage ledger.
 *
 * Every row represents exactly one billable selection. The unique index on
 * selection_id is the database-level guarantee that a replayed token can
 * never be billed twice.
 */
return new class extends Migration
{
    /**
     * Create the api_usage_events table.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('api_usage_events', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('api_client_id')->constrained('api_clients');
            $table->foreignId('record_id')->constrained('records');
            $table->uuid('selection_id')->unique();
            $table->uuid('search_id');
            $table->string('query', 100)->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['api_client_id', 'created_at'], 'api_usage_events_client_created_idx');
        });

        // Store the client IP as PostgreSQL INET when the value is valid.
        DB::statement(
            'ALTER TABLE api_usage_events
             ALTER COLUMN ip TYPE inet USING ip::inet'
        );
    }

    /**
     * Drop the usage ledger.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('api_usage_events');
    }
};
