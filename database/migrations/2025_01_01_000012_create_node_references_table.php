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
        // No live API calls — nothing is fetched. Validation patterns and link
        // templates live in config/arguesecure.php (W6+).
        Schema::create('node_references', function (Blueprint $table) {
            $table->id();
            $table->foreignId('node_id')->constrained('nodes')->cascadeOnDelete();
            $table->enum('kind', ['cve', 'cwe', 'capec', 'attack_technique', 'url', 'other']);
            // Required for every kind except `url`; normalized to uppercase.
            $table->string('identifier', 64)->nullable();
            // Required for `url` and `other`; derived for the rest.
            $table->string('url', 2048)->nullable();
            $table->string('title', 255)->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['node_id', 'kind', 'identifier'], 'node_references_unique');
            $table->index(['kind', 'identifier'], 'node_references_identifier_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('node_references');
    }
};
