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
        // Endpoint-type and same-assessment invariants (risk_node_id.type = 'risk',
        // attack_node_id.type = 'attack', both nodes share an assessment_id) are not
        // database-enforceable here and are asserted in LinkService and its tests (W6).
        Schema::create('risk_attack', function (Blueprint $table) {
            $table->id();
            $table->foreignId('risk_node_id')->constrained('nodes')->cascadeOnDelete();
            $table->foreignId('attack_node_id')->constrained('nodes')->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['risk_node_id', 'attack_node_id'], 'risk_attack_unique');
            $table->index(['attack_node_id', 'risk_node_id'], 'risk_attack_reverse_index');
            $table->index(['risk_node_id', 'position'], 'risk_attack_order_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('risk_attack');
    }
};
