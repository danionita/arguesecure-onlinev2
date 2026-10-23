<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * W126, D93.
     *
     * A "set" (the code's word for what the interface now calls a scale) was
     * never a row, so a name an admin chooses had nowhere to live.
     * `scales.set_key` stays a plain string and gains no foreign key: the 2.4
     * migration, the seeder and `ScaleSets::resolve()` read it as it is.
     *
     * `assessments.scale_set_key` records which scale an assessment's bands
     * were last set to; NULL means Custom. It is a label for the owner's eyes
     * and feeds no calculation, so nothing here changes a band, a scale FK or
     * `calc_version`.
     *
     * The two sets inserted are the built-in ones, and only when their system
     * rows exist: a migrated-but-unseeded instance gets `default` alone, which
     * is what `ScaleSets::selectableKeys()` already assumes. The literals are
     * deliberate, as in the W77 migration: a constant renamed later must not
     * change what this migration did.
     */
    public function up(): void
    {
        Schema::create('scale_sets', function (Blueprint $table) {
            $table->id();
            $table->string('key', 64)->unique();
            $table->string('name', 64)->unique();
            $table->boolean('is_builtin')->default(false);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::table('assessments', function (Blueprint $table) {
            $table->string('scale_set_key', 64)->nullable()->after('strength_scale_id');
        });

        $builtins = ['default' => 'Standard', 'small-organisation' => 'Small organisation'];
        $now = now();
        $position = 0;

        foreach ($builtins as $key => $name) {
            $has = DB::table('scales')->whereNull('assessment_id')->where('is_system', true)->where('set_key', $key)->exists();

            if ($has || $key === 'default') {
                DB::table('scale_sets')->insert([
                    'key' => $key,
                    'name' => $name,
                    'is_builtin' => true,
                    'position' => $position++,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        $this->backfill();
    }

    public function down(): void
    {
        Schema::table('assessments', function (Blueprint $table) {
            $table->dropColumn('scale_set_key');
        });

        Schema::dropIfExists('scale_sets');
    }

    /**
     * An assessment whose three scales equal the `default` set's, band for
     * band, is labelled `default`; any other is Custom. The only set that
     * could be chosen since 2.5.1 is `default`, so no other comparison can
     * be a true statement about how an assessment got its bands.
     */
    private function backfill(): void
    {
        $default = [];

        foreach (['impact', 'likelihood', 'strength'] as $kind) {
            $id = DB::table('scales')->whereNull('assessment_id')->where('is_system', true)
                ->where('set_key', 'default')->where('kind', $kind)->value('id');

            if ($id === null) {
                return;
            }

            $default[$kind] = $this->signature((int) $id);
        }

        DB::table('assessments')->orderBy('id')->select(['id', 'impact_scale_id', 'likelihood_scale_id', 'strength_scale_id'])
            ->each(function (object $row) use ($default): void {
                foreach (['impact', 'likelihood', 'strength'] as $kind) {
                    if ($this->signature((int) $row->{"{$kind}_scale_id"}) !== $default[$kind]) {
                        return;
                    }
                }

                DB::table('assessments')->where('id', $row->id)->update(['scale_set_key' => 'default']);
            });
    }

    /** @return list<list<string|null>> */
    private function signature(int $scaleId): array
    {
        return DB::table('scale_levels')->where('scale_id', $scaleId)->orderBy('ordinal')
            ->get(['label', 'lower_bound', 'upper_bound', 'representative'])
            ->map(fn (object $l): array => [
                $l->label,
                number_format((float) $l->lower_bound, 6, '.', ''),
                $l->upper_bound === null ? null : number_format((float) $l->upper_bound, 6, '.', ''),
                number_format((float) $l->representative, 6, '.', ''),
            ])->all();
    }
};
