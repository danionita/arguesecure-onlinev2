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
        // Assessment-scoped, a deliberate change from the legacy global tags table:
        // one user's rename used to hit everyone. Scoping makes colours meaningful,
        // cloning trivial, and deletion a cascade.
        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assessment_id')->constrained('assessments')->cascadeOnDelete();
            $table->string('name', 60);
            $table->string('slug', 60);
            $table->char('color', 7)->default('#3B82F6');
            $table->timestamps();

            $table->unique(['assessment_id', 'slug'], 'tags_slug_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tags');
    }
};
