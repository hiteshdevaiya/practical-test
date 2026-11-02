<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the vocabulary table used for cheap typo correction.
 *
 * It holds every distinct word from records.content with its frequency, so
 * fuzzy matching scans thousands of words instead of ten million rows.
 */
return new class extends Migration
{
    /**
     * Create the vocabulary table and trigram index.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('search_vocabulary', function (Blueprint $table): void {
            $table->text('word')->primary();
            $table->bigInteger('row_count');
        });

        DB::statement(
            'CREATE INDEX search_vocabulary_word_trgm_idx
             ON search_vocabulary USING GIN (word gin_trgm_ops)'
        );
    }

    /**
     * Drop the vocabulary table.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('search_vocabulary');
    }
};
