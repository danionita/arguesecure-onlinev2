<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who is responsible for a node (W46, item 16; schema.md §3.15).
 *
 * A pure join: no role, no timestamps, no `assigned_by`. An assignment
 * grants nothing — the assignee's collaborator role decides everything they
 * may do, and there is deliberately no node-scoped grant and no second
 * authorization path (security.md). Storing a role here would create one by
 * implication, so the table has nowhere to put one.
 *
 * Both foreign keys cascade. A deleted node's assignments are meaningless; a
 * deleted account's are unattributable. Losing *collaborator* access is a
 * different event and is handled at the model layer, not by a constraint —
 * see AssessmentUser's `deleted` hook.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('node_assignees', function (Blueprint $table) {
            $table->foreignId('node_id')->constrained('nodes')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->primary(['node_id', 'user_id']);
            // The composite primary key already serves "who is on this node?".
            // This index serves the opposite question — "what is assigned to
            // me?" — which is the dashboard's first section and runs on every
            // sign-in. Without it that query scans the table.
            $table->index(['user_id', 'node_id'], 'node_assignees_user_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('node_assignees');
    }
};
