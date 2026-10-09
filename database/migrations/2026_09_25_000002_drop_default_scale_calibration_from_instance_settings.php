<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 2.5.1: there is one default scale set — the one the admin Defaults tab
 * edits — so the setting that chose between seeded sets is gone.
 *
 * `default_scale_calibration` began as inert free text (W75 pinned it), was
 * made a real reference to a seeded set in W77 (D36), and then W87's Defaults
 * tab made the bands of the default set editable directly. From that point
 * the select was a second, older way of answering "what does a new assessment
 * start with", naming a set nobody could see or edit from the screen that
 * named it. The `small-organisation` set is still seeded, so assessments
 * created from it keep their scales; it is simply no longer offered.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('instance_settings', 'default_scale_calibration')) {
            Schema::table('instance_settings', function (Blueprint $table) {
                $table->dropColumn('default_scale_calibration');
            });
        }
    }

    public function down(): void
    {
        Schema::table('instance_settings', function (Blueprint $table) {
            $table->string('default_scale_calibration', 64)->default('default');
        });
    }
};
