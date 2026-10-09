<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * W44 (assessment settings, item 12).
     *
     * Only `risk_tolerance` is added. The package brief also listed `currency`,
     * but `assessments` has carried `char(3) default 'EUR'` since the original
     * schema and `amortization_years` alongside it — both were already there
     * and simply had no screen. Verified before writing this rather than
     * adding a duplicate column.
     *
     * **Nullable, with no default, on purpose.** "I have not set a tolerance"
     * and "my tolerance is zero" are different statements, and zero is a
     * legitimate one to make: it says every euro of residual loss is worth
     * arguing about. A default of 0 would put that claim in every existing
     * assessment without anyone deciding it, and the report would start
     * drawing a threshold marker at the bottom of every chart.
     *
     * decimal(20,2) matches `nodes.impact_amount` and the other money columns,
     * so a tolerance and the totals it is compared against are the same kind
     * of number. It is **display only** (PLAN-2.2.md D14): it renders a marker
     * and a badge, and never enters RiskCalculator — see the pinned test in
     * tests/Feature/Assessment/SettingsTest.php.
     */
    public function up(): void
    {
        Schema::table('assessments', function (Blueprint $table) {
            $table->decimal('risk_tolerance', 20, 2)->nullable()->after('amortization_years');
        });
    }

    public function down(): void
    {
        Schema::table('assessments', function (Blueprint $table) {
            $table->dropColumn('risk_tolerance');
        });
    }
};
