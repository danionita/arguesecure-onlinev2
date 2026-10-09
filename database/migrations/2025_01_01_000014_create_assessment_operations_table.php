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
        // One linear stack per assessment, not per user — per-user undo in a live
        // multi-user editor produces incoherent history. Only structural changes
        // enter the stack; field edits are recovered via node_revisions instead.
        Schema::create('assessment_operations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assessment_id')->constrained('assessments')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('kind', 64);
            $table->string('summary', 255);
            $table->json('inverse');
            $table->dateTime('undone_at')->nullable();
            $table->foreignId('undone_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('created_at');

            $table->index(['assessment_id', 'undone_at', 'id'], 'assessment_operations_stack_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('assessment_operations');
    }
};
