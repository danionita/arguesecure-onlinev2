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
        Schema::create('scale_levels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scale_id')->constrained('scales')->cascadeOnDelete();
            $table->unsignedTinyInteger('ordinal');
            // Machine name, e.g. `moderate`. Catalogue defaults reference levels by
            // code, not id, so a catalogue entry resolves against any calibration.
            $table->string('code', 32);
            $table->string('label', 64);
            $table->char('color', 7)->default('#9CA3AF');
            $table->decimal('lower_bound', 20, 6);
            // Exclusive; NULL = +infinity, permitted on the top band only.
            $table->decimal('upper_bound', 20, 6)->nullable();
            $table->decimal('representative', 20, 6);
            $table->timestamps();

            $table->unique(['scale_id', 'ordinal'], 'scale_levels_ordinal_unique');
            $table->unique(['scale_id', 'code'], 'scale_levels_code_unique');
            $table->index(['scale_id', 'lower_bound'], 'scale_levels_lookup_index');
        });

        // Contiguity, the lower==0 start, the open top band and the
        // lower <= representative < upper invariant are validated in ScaleValidator
        // (W6), not the database — they require comparing sibling rows.
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('scale_levels');
    }
};
