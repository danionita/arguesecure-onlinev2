<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * W121 (D83). A new operation takes every undone one off the redo stack, or
 * undo, edit something else, redo replays an old inverse over newer work.
 * It cannot be derived: redo itself pushes a row, which would count as "newer
 * work" and invalidate the rest of the stack, so the row is stamped instead.
 * Stamped rows stay `undone_at` (History still shows them as undone) and
 * simply cannot be redone.
 *
 * Rows undone before this migration are not stamped, so an old stack stays
 * redoable until the next new operation clears it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assessment_operations', function (Blueprint $table) {
            $table->dateTime('superseded_at')->nullable()->after('redone_at');
        });
    }

    public function down(): void
    {
        Schema::table('assessment_operations', function (Blueprint $table) {
            $table->dropColumn('superseded_at');
        });
    }
};
