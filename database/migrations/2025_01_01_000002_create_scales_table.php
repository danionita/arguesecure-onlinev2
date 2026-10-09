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
        // Created before `assessments`, which references this table. The reverse edge
        // (scales.assessment_id -> assessments.id) is circular and added in a follow-up
        // migration once `assessments` exists — see schema.md §6.
        Schema::create('scales', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('assessment_id')->nullable();
            $table->enum('kind', ['impact', 'likelihood', 'strength']);
            $table->string('name', 120);
            // Derived from `kind` but stored so ScaleMapper can assert unit agreement.
            $table->enum('unit', ['currency', 'rate_per_year', 'fraction']);
            $table->boolean('is_system')->default(false);
            $table->timestamps();

            $table->index(['assessment_id', 'kind'], 'scales_assessment_kind_index');
            $table->index(['is_system', 'kind'], 'scales_system_kind_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('scales');
    }
};
