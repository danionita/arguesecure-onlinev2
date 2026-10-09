<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One switch per assistant feature (W87, item 20, PLAN-2.5.md D48).
     *
     * `assistant_enabled` is one answer to three different questions. D25's
     * three features cost wildly different amounts — help is a corpus read,
     * a suggestion is roughly 30 seconds of CPU, a summary roughly 250 — so an
     * admin may reasonably want the cheap one and not the expensive one, and
     * until now the only way to decline the expensive one was to decline all
     * three.
     *
     * ## These can only ever subtract
     *
     * Each is **subordinate to `Availability`**, which already requires a
     * deployment runtime, the instance switch above them and a healthy probe.
     * A feature switch is a fourth condition, never a replacement for any of
     * the three: no value written here can make a feature work on a deployment
     * that runs no inference container. `UpdateSettingsRequest` enforces that
     * at the edge with `declined`, the same rule `assistant_enabled` has had
     * since W50, and `Availability::isAvailable($feature)` enforces it at
     * every call site.
     *
     * ## Default true, unlike `assistant_enabled`
     *
     * The instance switch defaults **off**: adding an inference container does
     * not thereby hand the feature to users, somebody decides. These three
     * default **on**, because the decision has already been made by the time
     * they are read — they are only consulted once an admin has switched the
     * assistant on, and an admin who enables the assistant and finds none of
     * its three features working has been given a control that appears to do
     * something and does not. Defaulting them off would mean two switches to
     * turn one feature on.
     */
    public function up(): void
    {
        Schema::table('instance_settings', function (Blueprint $table) {
            $table->boolean('assistant_help_enabled')->default(true)->after('assistant_enabled');
            $table->boolean('assistant_suggestions_enabled')->default(true)->after('assistant_help_enabled');
            $table->boolean('assistant_summary_enabled')->default(true)->after('assistant_suggestions_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('instance_settings', function (Blueprint $table) {
            $table->dropColumn([
                'assistant_help_enabled',
                'assistant_suggestions_enabled',
                'assistant_summary_enabled',
            ]);
        });
    }
};
