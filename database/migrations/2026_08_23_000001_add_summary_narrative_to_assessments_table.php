<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * W54 — the report's written executive summary, and the draft waiting to
     * become one (PLAN-2.3-ai.md).
     *
     * Three columns, and the third is the reason for the other two.
     *
     * `summary_narrative` is prose the report prints above the figures. A
     * person may type it themselves and most will; nothing here is
     * assistant-specific and nothing about it stops working on a deployment
     * that has no inference runtime, which is the overwhelmingly common case
     * (D25).
     *
     * `summary_draft` holds what the model wrote, until a person accepts it,
     * edits it, or throws it away. It is deliberately **not** the same column
     * as the narrative: a draft arriving asynchronously must never overwrite
     * text someone is in the middle of writing, and a queued job that lands
     * four minutes after it was asked for would do exactly that.
     *
     * `summary_narrative_generated` is the disclosure, and it is a column
     * rather than an inference because the report has to state it in the
     * methodology appendix and a reader has to be able to trust that
     * statement. It is set when a draft is accepted and cleared only when the
     * narrative is cleared or replaced by text the person wrote from nothing —
     * **editing a generated draft does not clear it**, because a lightly
     * reworded model draft is still a model draft, and the disclaimer exists
     * for the reader, not for the author's sense of ownership.
     *
     * Nothing records *which* model wrote it. The assistant is configuration
     * and may be gone tomorrow; an assessment that could not open because a
     * GGUF was unmounted would be a hard dependency on an optional feature.
     */
    public function up(): void
    {
        Schema::table('assessments', function (Blueprint $table) {
            $table->text('summary_narrative')->nullable()->after('risk_tolerance');
            $table->boolean('summary_narrative_generated')->default(false)->after('summary_narrative');
            $table->text('summary_draft')->nullable()->after('summary_narrative_generated');
        });
    }

    public function down(): void
    {
        Schema::table('assessments', function (Blueprint $table) {
            $table->dropColumn([
                'summary_narrative',
                'summary_narrative_generated',
                'summary_draft',
            ]);
        });
    }
};
