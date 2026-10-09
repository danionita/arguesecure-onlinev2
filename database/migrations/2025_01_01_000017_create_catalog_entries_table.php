<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Level codes, never level ids, belong in `defaults` (schema.md §3.14) — that
        // is what lets a catalogue entry resolve correctly against any assessment's
        // calibration at insert time.
        Schema::create('catalog_entries', function (Blueprint $table) {
            $table->id();
            $table->enum('kind', ['risk', 'attack', 'defence']);
            $table->string('title', 255);
            $table->string('description', 500)->nullable();
            $table->mediumText('notes')->nullable();
            $table->json('defaults')->nullable();
            // NULL = system catalogue.
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->boolean('is_public')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('catalog_entries');
    }
};
