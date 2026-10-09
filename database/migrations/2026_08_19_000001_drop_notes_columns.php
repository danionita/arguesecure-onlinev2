<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drops `notes` from `nodes` and `catalog_entries` (PLAN-2.1.md W30, D2).
 *
 * `notes` duplicated `description` in everything but length and was surfaced
 * nowhere except as an indicator dot on the node card, while costing a field
 * in the modal, a markdown preview renderer, a lockable field, a branch in
 * search, and a column in the report, the JSON export, snapshots and the
 * catalogue. Removing it is removing a whole seam, not a column.
 *
 * ## This destroys data, deliberately and irreversibly
 *
 * The content is **not** merged into `description` first, though that was the
 * obvious kindness: `nodes.description` is `varchar(500)` and validated at
 * `max:500` on purpose — it is the snippet the card and the chart view show —
 * while `notes` was `mediumText` holding long-form markdown. Merging would
 * either overflow the column or silently truncate someone's writing, and
 * widening `description` would break the one guarantee that makes it usable
 * as a snippet.
 *
 * So: take a dump before running this on a database with real content in it.
 * `down()` restores the columns, empty — the shape comes back, the writing
 * does not.
 *
 * The `nodes_search_fulltext` index covers `title` and `description` only, so
 * it is untouched here; nothing needs rebuilding.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $table->dropColumn('notes');
        });

        Schema::table('catalog_entries', function (Blueprint $table) {
            $table->dropColumn('notes');
        });
    }

    public function down(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $table->mediumText('notes')->nullable()->after('description');
        });

        Schema::table('catalog_entries', function (Blueprint $table) {
            $table->mediumText('notes')->nullable()->after('description');
        });
    }
};
