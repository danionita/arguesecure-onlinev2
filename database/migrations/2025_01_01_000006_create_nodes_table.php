<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('nodes', function (Blueprint $table) {
            $table->id();
            // Stable identity across clones and snapshots; the client-side key.
            $table->uuid('uuid')->unique();
            $table->foreignId('assessment_id')->constrained('assessments')->cascadeOnDelete();
            $table->enum('type', ['root', 'risk', 'attack', 'defence']);
            // May be empty string immediately after a keyboard insert.
            $table->string('title', 255);
            $table->string('description', 500)->nullable();
            $table->mediumText('notes')->nullable();
            // Order of risks under the root and of defences in the assessment library.
            // Attack order lives on risk_attack.position; defence order on attack_defence.position.
            $table->unsignedInteger('position')->default(0);

            // Risk only.
            $table->enum('impact_mode', ['quantitative', 'qualitative'])->nullable();
            $table->decimal('impact_amount', 20, 2)->nullable();
            $table->foreignId('impact_level_id')->nullable()->constrained('scale_levels')->restrictOnDelete();

            // Attack only.
            $table->enum('likelihood_mode', ['quantitative', 'qualitative'])->nullable();
            $table->decimal('likelihood_aro', 14, 6)->nullable();
            $table->foreignId('likelihood_level_id')->nullable()->constrained('scale_levels')->restrictOnDelete();

            // Meaningful for defences only; NOT NULL for all rows per schema.md §1 mitigation table.
            $table->boolean('is_transfer')->default(false);

            // Defence only.
            $table->enum('status', ['implemented', 'todo', 'shelved'])->nullable();
            $table->decimal('capex_amount', 20, 2)->nullable();
            $table->decimal('opex_annual_amount', 20, 2)->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            // Required: undo and history depend on rows surviving deletion.
            $table->softDeletes();

            $table->index(['assessment_id', 'type', 'deleted_at', 'position'], 'nodes_assessment_type_index');
            $table->index('impact_level_id', 'nodes_impact_level_index');
            $table->index('likelihood_level_id', 'nodes_likelihood_level_index');
            $table->index('updated_by', 'nodes_updated_by_index');

            $table->fullText(['title', 'description'], 'nodes_search_fulltext');
        });

        // Generated column enforcing exactly one root node per assessment: NULL for every
        // non-root row (so they never collide on the unique index), assessment_id for root
        // rows (so a second root for the same assessment collides on nodes_root_unique).
        // Laravel's Blueprint has no generated-column helper, so this is raw DDL.
        DB::statement(<<<'SQL'
            ALTER TABLE nodes
            ADD COLUMN root_marker BIGINT UNSIGNED
                GENERATED ALWAYS AS (CASE WHEN `type` = 'root' THEN `assessment_id` END) VIRTUAL,
            ADD UNIQUE INDEX nodes_root_unique (root_marker)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE nodes ADD CONSTRAINT chk_nodes_risk_cols CHECK (
                `type` = 'risk' OR (impact_mode IS NULL AND impact_amount IS NULL AND impact_level_id IS NULL)
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE nodes ADD CONSTRAINT chk_nodes_attack_cols CHECK (
                `type` = 'attack' OR (likelihood_mode IS NULL AND likelihood_aro IS NULL AND likelihood_level_id IS NULL)
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE nodes ADD CONSTRAINT chk_nodes_defence_cols CHECK (
                `type` = 'defence' OR (status IS NULL AND capex_amount IS NULL AND opex_annual_amount IS NULL)
            )
        SQL);

        // Deliberately permits mode set with the value still NULL: the legitimate
        // "user picked quantitative but has not typed a number yet" state, which the
        // engine treats as missing (calculation-engine.md §6).
        DB::statement(<<<'SQL'
            ALTER TABLE nodes ADD CONSTRAINT chk_nodes_impact_mode CHECK (
                impact_mode IS NULL
                OR (impact_mode = 'quantitative' AND impact_level_id IS NULL)
                OR (impact_mode = 'qualitative' AND impact_amount IS NULL)
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE nodes ADD CONSTRAINT chk_nodes_likelihood_mode CHECK (
                likelihood_mode IS NULL
                OR (likelihood_mode = 'quantitative' AND likelihood_level_id IS NULL)
                OR (likelihood_mode = 'qualitative' AND likelihood_aro IS NULL)
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE nodes ADD CONSTRAINT chk_nodes_nonneg CHECK (
                (impact_amount IS NULL OR impact_amount >= 0)
                AND (likelihood_aro IS NULL OR likelihood_aro >= 0)
                AND (capex_amount IS NULL OR capex_amount >= 0)
                AND (opex_annual_amount IS NULL OR opex_annual_amount >= 0)
            )
        SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('nodes');
    }
};
