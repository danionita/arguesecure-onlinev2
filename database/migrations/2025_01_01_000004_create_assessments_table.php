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
        // No `title` column: title and description live on the root node.
        // Listings join nodes ON nodes.assessment_id = assessments.id AND nodes.type = 'root'.
        Schema::create('assessments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('owner_user_id')->constrained('users')->cascadeOnDelete();
            $table->char('currency', 3)->default('EUR');
            $table->enum('display_mode', ['quantitative', 'qualitative', 'mixed'])->default('mixed');
            $table->foreignId('impact_scale_id')->constrained('scales')->restrictOnDelete();
            $table->foreignId('likelihood_scale_id')->constrained('scales')->restrictOnDelete();
            $table->foreignId('strength_scale_id')->constrained('scales')->restrictOnDelete();
            $table->unsignedTinyInteger('amortization_years')->default(3);
            $table->boolean('is_public')->default(false);
            $table->char('share_token', 43)->nullable()->unique();
            $table->dateTime('share_token_created_at')->nullable();
            $table->boolean('is_template')->default(false);
            // Bumped on any change affecting totals; the calculation cache key.
            $table->unsignedInteger('calc_version')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['owner_user_id', 'deleted_at'], 'assessments_owner_index');
            $table->index(['is_public', 'deleted_at'], 'assessments_public_index');
            $table->index('is_template', 'assessments_template_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('assessments');
    }
};
