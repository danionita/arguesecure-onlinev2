<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * W108. A redo pushes a new operation (schema.md §3.10), and the row it
 * consumed has to leave the stack in both directions: it is still undone, so
 * undo must skip it, and it has been redone, so redo must too. Before this
 * column the row's `undone_at` was cleared instead, which put it back on the
 * undo stack while its `inverse` held the *redo* command, so a second undo
 * re-applied the change.
 *
 * `undone_at` gains microseconds for the same package: redo takes the most
 * recently undone row, and at one-second precision two undos in one second
 * tie. No tiebreak on `id` is right for every case: Ctrl+Z, Ctrl+Z needs the
 * lower id, and undo, redo, undo needs the higher (the redo pushed a row).
 *
 * Rows redone before this migration are not repaired: which of them hold a
 * redo command cannot be told reliably from the row alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assessment_operations', function (Blueprint $table) {
            $table->dateTime('undone_at', 6)->nullable()->change();
            $table->dateTime('redone_at')->nullable()->after('undone_by');
        });
    }

    public function down(): void
    {
        Schema::table('assessment_operations', function (Blueprint $table) {
            $table->dropColumn('redone_at');
            $table->dateTime('undone_at')->nullable()->change();
        });
    }
};
