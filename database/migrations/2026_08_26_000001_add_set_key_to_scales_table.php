<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A scale *set* becomes a real thing (W77, PLAN-2.4.md D36).
     *
     * Until now a "system scale set" was an implicit trio of `scales` rows
     * sharing `is_system = 1, assessment_id IS NULL` and nothing else. There
     * was exactly one such trio, so nothing ever had to tell two of them
     * apart — and `instance_settings.default_scale_calibration`, which is
     * meant to name the set a new assessment starts from, was free text that
     * no code path read. `set_key` is the grouping key that makes both of
     * those answerable.
     *
     * Nullable, because it only means anything for a shared system scale. An
     * assessment's own calibration — ScaleCalibrator's copy-on-write, or
     * AssessmentCloner's copy of a custom scale — belongs to that assessment
     * and to no shared set, and `NULL` says so rather than a sentinel that
     * every query would then have to exclude.
     *
     * ## What this does to existing rows
     *
     * 1. Every existing system scale (`is_system = 1, assessment_id IS NULL`)
     *    is stamped `set_key = 'default'`. Any existing deployment has
     *    exactly one such trio and it is the one SystemScaleSeeder wrote, so
     *    this is an exact mapping rather than a guess.
     * 2. Assessment-owned scales keep `NULL`.
     * 3. Every `instance_settings.default_scale_calibration` that is not
     *    literally `'default'` is rewritten to `'default'`. Those values are
     *    whatever an admin typed into a free-text box that nothing read —
     *    `strict`, sixty-four characters of noise, anything. The moment the
     *    field becomes a *reference* to a set, a value naming no set would
     *    either fail the new `in:` rule the next time an admin saves an
     *    unrelated setting, or sit there forever while AssessmentCreator
     *    quietly used the fallback and the page claimed otherwise. This
     *    migration is the only point at which the old free text is
     *    unambiguously meaningless, so it is the only honest place to
     *    normalise it.
     *
     * The second system set is deliberately **not** created here:
     * schema.md §3.2 is explicit that system scales belong in a seeder, not a
     * migration. The literal `'default'` is likewise deliberate — a migration
     * that imported App\Domain\Scales\ScaleSets::DEFAULT_KEY would change
     * meaning retroactively if that constant were ever renamed.
     */
    public function up(): void
    {
        Schema::table('scales', function (Blueprint $table) {
            $table->string('set_key', 64)->nullable()->after('is_system');
            // The lookup AssessmentCreator performs on every creation, and
            // the one the settings page's option list is built from.
            $table->index(['is_system', 'set_key'], 'scales_system_set_index');
        });

        DB::table('scales')
            ->whereNull('assessment_id')
            ->where('is_system', true)
            ->update(['set_key' => 'default']);

        DB::table('instance_settings')
            ->where('default_scale_calibration', '!=', 'default')
            ->update(['default_scale_calibration' => 'default']);
    }

    public function down(): void
    {
        Schema::table('scales', function (Blueprint $table) {
            $table->dropIndex('scales_system_set_index');
            $table->dropColumn('set_key');
        });
    }
};
