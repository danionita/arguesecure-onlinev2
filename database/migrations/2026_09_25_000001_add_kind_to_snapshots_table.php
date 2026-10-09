<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 2.5.1: snapshots are presented as *versions* on the History page, and some
 * are now taken automatically (AutoVersioner). `kind` says which is which:
 *
 * - `manual` — someone chose to save one (every snapshot before this).
 * - `auto`   — saved by the scheduler after a pause in editing or a run of
 *              changes.
 * - `safety` — taken by a restore so the restore can be undone.
 *
 * A string rather than an enum column, for the same reason `scale_levels`'
 * kinds are: adding a fourth value should not need an ALTER on a table that
 * holds large compressed graphs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('snapshots', function (Blueprint $table) {
            $table->string('kind', 16)->default('manual')->after('note');
        });

        // The restore path has always named its safety copies this way
        // (SnapshotRestorer::safetySnapshot), so they can be told apart.
        DB::table('snapshots')
            ->where('name', 'like', 'Before restoring %')
            ->update(['kind' => 'safety']);
    }

    public function down(): void
    {
        Schema::table('snapshots', function (Blueprint $table) {
            $table->dropColumn('kind');
        });
    }
};
