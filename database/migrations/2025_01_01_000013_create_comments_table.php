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
        // Threading depth is exactly two levels; enforced in CommentService, not here.
        // Comments attach to nodes only, not to links (see schema.md §3.8).
        Schema::create('comments', function (Blueprint $table) {
            $table->id();
            // Denormalized for channel scoping and authorization without a join.
            $table->foreignId('assessment_id')->constrained('assessments')->cascadeOnDelete();
            $table->foreignId('node_id')->constrained('nodes')->cascadeOnDelete();
            $table->foreignId('parent_comment_id')->nullable()->constrained('comments')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body');
            $table->dateTime('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['node_id', 'created_at'], 'comments_node_index');
            $table->index(['parent_comment_id', 'created_at'], 'comments_thread_index');
            $table->index(['assessment_id', 'created_at'], 'comments_assessment_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('comments');
    }
};
