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
        // Immutable per-field change log: no updated_at, node_id nullable ON DELETE
        // SET NULL so history outlives a purge, node_uuid denormalized so it stays
        // readable after one.
        Schema::create('node_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assessment_id')->constrained('assessments')->cascadeOnDelete();
            $table->foreignId('node_id')->nullable()->constrained('nodes')->nullOnDelete();
            $table->uuid('node_uuid');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('action', ['create', 'update', 'delete', 'restore', 'move', 'link', 'unlink', 'link_update']);
            $table->json('changes');
            // Groups revisions written by one user action.
            $table->foreignId('operation_id')->nullable()->constrained('assessment_operations')->nullOnDelete();
            $table->dateTime('created_at');

            $table->index(['node_id', 'id'], 'node_revisions_node_index');
            $table->index(['assessment_id', 'id'], 'node_revisions_assessment_index');
            $table->index('operation_id', 'node_revisions_operation_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('node_revisions');
    }
};
