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
        // Lets an entry be a bundle — e.g. a "Phishing" entry shipping with MFA,
        // awareness training and mail filtering pre-linked at default strengths.
        Schema::create('catalog_entry_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_entry_id')->constrained('catalog_entries')->cascadeOnDelete();
            $table->foreignId('child_entry_id')->constrained('catalog_entries')->cascadeOnDelete();
            // Pivot defaults such as a default strength level code.
            $table->json('defaults')->nullable();
            $table->timestamps();

            $table->unique(['parent_entry_id', 'child_entry_id'], 'catalog_entry_links_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('catalog_entry_links');
    }
};
