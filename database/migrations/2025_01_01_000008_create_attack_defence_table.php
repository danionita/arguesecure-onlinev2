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
        // Strength lives on this pivot, not on the defence, because the same control
        // may be 80% effective against one attack and 20% against another.
        Schema::create('attack_defence', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attack_node_id')->constrained('nodes')->cascadeOnDelete();
            $table->foreignId('defence_node_id')->constrained('nodes')->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);
            // NULL = strength not yet stated.
            $table->enum('strength_mode', ['quantitative', 'qualitative'])->nullable();
            $table->decimal('strength_fraction', 7, 6)->nullable();
            $table->foreignId('strength_level_id')->nullable()->constrained('scale_levels')->restrictOnDelete();
            // NULL = combined loss, and NULL is the default.
            $table->enum('reduction_target', ['likelihood', 'impact'])->nullable();
            $table->string('rationale', 500)->nullable();
            $table->timestamps();

            $table->unique(['attack_node_id', 'defence_node_id'], 'attack_defence_unique');
            $table->index(['defence_node_id', 'attack_node_id'], 'attack_defence_reverse_index');
            $table->index(['attack_node_id', 'position'], 'attack_defence_order_index');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE attack_defence ADD CONSTRAINT chk_ad_strength_mode CHECK (
                strength_mode IS NULL
                OR (strength_mode = 'quantitative' AND strength_level_id IS NULL)
                OR (strength_mode = 'qualitative' AND strength_fraction IS NULL)
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE attack_defence ADD CONSTRAINT chk_ad_fraction CHECK (
                strength_fraction IS NULL OR (strength_fraction >= 0 AND strength_fraction <= 1)
            )
        SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attack_defence');
    }
};
