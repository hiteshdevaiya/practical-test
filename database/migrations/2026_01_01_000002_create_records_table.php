<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the searchable records table and its GIN trigram index.
 *
 * The table intentionally has no timestamps: rows are static demo data and
 * smaller rows keep more of the table and its index in memory.
 */
return new class extends Migration
{
    /**
     * Create the table and index.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('records', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->text('content');
        });

        // GIN is faster to search than GiST and the dataset is read-only.
        // fastupdate is disabled so reads never merge a pending list.
        DB::statement(
            'CREATE INDEX records_content_trgm_idx
             ON records USING GIN (content gin_trgm_ops)
             WITH (fastupdate = off)'
        );
    }

    /**
     * Drop the table and its index.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('records');
    }
};
