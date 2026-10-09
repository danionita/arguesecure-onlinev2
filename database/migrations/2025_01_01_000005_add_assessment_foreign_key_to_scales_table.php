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
        // `scales` was created before `assessments` could exist (assessments references
        // scales), so this circular edge is added here per schema.md §6. NULL
        // assessment_id means a system template scale.
        Schema::table('scales', function (Blueprint $table) {
            $table->foreign('assessment_id')
                ->references('id')->on('assessments')
                ->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('scales', function (Blueprint $table) {
            $table->dropForeign(['assessment_id']);
        });
    }
};
