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
        // Scales are inlined into `graph` so recalibrating later cannot silently
        // rewrite history, and `results` is frozen so reports are reproducible.
        // Snapshot reports never recompute.
        Schema::create('snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assessment_id')->constrained('assessments')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('note', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            // gzip + base64 of the canonical JSON graph: nodes keyed by uuid, both
            // pivots, scales inlined.
            $table->longText('graph');
            // The frozen CalculationResult for all three baselines.
            $table->json('results');
            $table->unsignedSmallInteger('schema_version');
            $table->dateTime('created_at');

            $table->index(['assessment_id', 'created_at'], 'snapshots_assessment_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('snapshots');
    }
};
