<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Creates API clients and their billable usage counters.
 *
 * Only a SHA-256 hash of each API key is stored; the plain key is shown once
 * when it is generated and can never be recovered from the database.
 */
return new class extends Migration
{
    /**
     * Create the api_clients table.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('api_clients', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('name', 100);
            $table->char('api_key_hash', 64)->unique();
            $table->bigInteger('billable_usage_count')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestampTz('created_at')->nullable();
            $table->timestampTz('updated_at')->nullable();
        });

        DB::statement(
            'ALTER TABLE api_clients
             ADD CONSTRAINT api_clients_usage_count_non_negative
             CHECK (billable_usage_count >= 0)'
        );
    }

    /**
     * Drop the api_clients table.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('api_clients');
    }
};
