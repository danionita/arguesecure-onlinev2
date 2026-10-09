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
        // Role ladder: viewer < commenter < editor < manager. The owner is
        // assessments.owner_user_id, not a row here; only the owner or an admin
        // transfers ownership or hard-deletes.
        Schema::create('assessment_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assessment_id')->constrained('assessments')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->enum('role', ['viewer', 'commenter', 'editor', 'manager']);
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('invited_at');
            $table->dateTime('accepted_at')->nullable();
            $table->timestamps();

            $table->unique(['assessment_id', 'user_id'], 'assessment_user_unique');
            $table->index(['user_id', 'accepted_at'], 'assessment_user_user_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('assessment_user');
    }
};
